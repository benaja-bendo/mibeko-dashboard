<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * Couvre l'export PDF Mibeko (`/legal-documents/{id}/export` et
 * `/articles/{id}/export`) : génération effective du PDF avec la mise en
 * page Mibeko (couverture, sommaire, corps) sans erreur de rendu Blade.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    $institution = Institution::factory()->create(['nom' => 'Ministère de la Justice']);

    $this->document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi de test sur l\'export PDF',
        'curation_status' => 'published',
        'legal_scope' => 'national',
        'institution_id' => $institution->id,
        'date_publication' => '2024-05-01',
    ]);

    $node = StructureNode::factory()->create([
        'document_id' => $this->document->id,
        'type_unite' => 'Titre',
        'numero' => 'I',
        'titre' => 'Dispositions générales',
        'tree_path' => '1',
    ]);

    $this->article = Article::factory()->create([
        'document_id' => $this->document->id,
        'parent_node_id' => $node->id,
        'numero_article' => '1',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article->id,
        'contenu_texte' => 'Le présent texte régit l\'export PDF de la plateforme.',
        'validity_period' => '[2024-05-01,)',
    ]);

    // Article hors structure (dispositions complémentaires).
    $orphan = Article::factory()->create([
        'document_id' => $this->document->id,
        'parent_node_id' => null,
        'numero_article' => '2',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $orphan->id,
        'contenu_texte' => 'Disposition complémentaire de test.',
        'validity_period' => '[2024-05-01,)',
    ]);
});

it('exporte un document complet en PDF', function () {
    $response = $this->get("/api/v1/legal-documents/{$this->document->id}/export");

    $response->assertStatus(200)
        ->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

it('exporte un article seul en PDF', function () {
    $response = $this->get("/api/v1/articles/{$this->article->id}/export");

    $response->assertStatus(200)
        ->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

it('renvoie 404 pour un document inexistant', function () {
    $this->get('/api/v1/legal-documents/00000000-0000-0000-0000-000000000000/export')
        ->assertStatus(404);
});
