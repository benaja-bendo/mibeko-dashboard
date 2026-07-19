<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Garde d'accès au corpus non publié via les relations (audit — FIX-2).
 *
 * `GET /api/v1/relations/search` (searchTargets) et
 * `GET /api/v1/articles/{article}/relations` (index) sont hors `auth:sanctum`.
 * Un appelant non privilégié (anonyme ou compte sans rôle éditorial) ne doit y
 * voir que le corpus publié : ni brouillon dans la recherche de cibles, ni
 * relation révélant l'existence d'un texte non publié. Les éditeurs/admins
 * gardent la vue complète (le dashboard React travaille sur les brouillons).
 */
beforeEach(function () {
    $this->published = LegalDocument::factory()->create([
        'titre_officiel' => 'Loi publiée sur les relations',
        'reference_nor' => 'PUBRELATION2026',
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
    ]);
    $this->publishedArticle = Article::factory()->create([
        'document_id' => $this->published->id,
        'numero_article' => '101',
    ]);
    ArticleVersion::factory()->create(['article_id' => $this->publishedArticle->id]);

    $this->draft = LegalDocument::factory()->create([
        'titre_officiel' => 'Brouillon confidentiel sur les relations',
        'reference_nor' => 'DRAFTRELATION2026',
        'curation_status' => LegalDocument::STATUS_DRAFT,
    ]);
    $this->draftArticle = Article::factory()->create([
        'document_id' => $this->draft->id,
        'numero_article' => '101',
    ]);
    ArticleVersion::factory()->create(['article_id' => $this->draftArticle->id]);

    $this->editor = User::factory()->create();
    $this->editor->assignRole(Role::findOrCreate('editor'));
});

it('ne matche que les documents publiés dans la recherche de cibles pour un anonyme', function () {
    $response = $this->getJson('/api/v1/relations/search?q=relations')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($this->published->id)
        ->and($ids)->not->toContain($this->draft->id);
});

it('ne matche que les articles de documents publiés dans la recherche de cibles pour un anonyme', function () {
    $response = $this->getJson('/api/v1/relations/search?q=101')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($this->publishedArticle->id)
        ->and($ids)->not->toContain($this->draftArticle->id);
});

it('expose tout le corpus à l\'éditeur dans la recherche de cibles', function () {
    $response = $this->actingAs($this->editor)
        ->getJson('/api/v1/relations/search?q=relations')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain($this->published->id)
        ->and($ids)->toContain($this->draft->id);
});

it('répond 404 sur les relations d\'un article de brouillon pour un anonyme, 200 pour un éditeur', function () {
    $this->getJson("/api/v1/articles/{$this->draftArticle->id}/relations")
        ->assertNotFound();

    $this->actingAs($this->editor)
        ->getJson("/api/v1/articles/{$this->draftArticle->id}/relations")
        ->assertOk();
});

it('exclut les relations pointant vers un document non publié pour un anonyme', function () {
    // Relation d'un article publié vers un brouillon : ne doit pas fuiter.
    DocumentRelation::factory()->create([
        'source_doc_id' => $this->published->id,
        'source_article_id' => $this->publishedArticle->id,
        'target_doc_id' => $this->draft->id,
        'target_article_id' => $this->draftArticle->id,
        'relation_type' => 'CITE',
    ]);

    // Relation entièrement dans le corpus publié : doit rester visible.
    $otherPublished = LegalDocument::factory()->create([
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
    ]);
    $otherArticle = Article::factory()->create(['document_id' => $otherPublished->id]);
    ArticleVersion::factory()->create(['article_id' => $otherArticle->id]);

    DocumentRelation::factory()->create([
        'source_doc_id' => $this->published->id,
        'source_article_id' => $this->publishedArticle->id,
        'target_doc_id' => $otherPublished->id,
        'target_article_id' => $otherArticle->id,
        'relation_type' => 'CITE',
    ]);

    $anon = $this->getJson("/api/v1/articles/{$this->publishedArticle->id}/relations")
        ->assertOk();

    $targetDocIds = collect($anon->json('data'))->pluck('target_doc_id')->all();
    expect($targetDocIds)->toContain($otherPublished->id)
        ->and($targetDocIds)->not->toContain($this->draft->id);

    // L'éditeur voit les deux relations (dont celle vers le brouillon).
    $this->actingAs($this->editor)
        ->getJson("/api/v1/articles/{$this->publishedArticle->id}/relations")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
