<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Models\User;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

function editeurPourTriage(): User
{
    Role::findOrCreate('editor');

    $editor = User::factory()->create();
    $editor->assignRole('editor');

    return $editor;
}

it('détecte une relation candidate via /legal-documents/{id}/detect-relations', function () {
    $editor = editeurPourTriage();

    LegalDocument::factory()->create([
        'titre_officiel' => 'Décret n° 2025-100 du 3 janvier 2025 portant organisation.',
        'date_signature' => '2025-01-03',
    ]);
    $source = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $source->id]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Le présent décret abroge le décret n° 2025-100 du 3 janvier 2025.',
    ]);

    $response = $this->actingAs($editor)
        ->postJson("/api/v1/legal-documents/{$source->id}/detect-relations")
        ->assertOk();

    expect($response->json('data.candidats'))->toHaveCount(1);
    $this->assertDatabaseHas('document_relations', [
        'source_article_id' => $article->id,
        'relation_type' => 'ABROGE',
        'status' => 'candidate',
    ]);
});

it('refuse la détection à un appelant non authentifié', function () {
    $document = LegalDocument::factory()->create();

    $this->postJson("/api/v1/legal-documents/{$document->id}/detect-relations")
        ->assertUnauthorized();
});

it('liste les relations en triage global avec filtre par statut', function () {
    $editor = editeurPourTriage();

    DocumentRelation::factory()->candidate()->create();
    DocumentRelation::factory()->confirmed()->create();

    $response = $this->actingAs($editor)
        ->getJson('/api/v1/document-relations?status=candidate')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.status'))->toBe('candidate');
});

it('valide une relation candidate et trace qui/quand', function () {
    $editor = editeurPourTriage();
    $relation = DocumentRelation::factory()->candidate()->create();

    $this->actingAs($editor)
        ->postJson("/api/v1/relations/{$relation->id}/valider")
        ->assertOk();

    $relation->refresh();
    expect($relation->status)->toBe(DocumentRelation::STATUS_CONFIRMED)
        ->and($relation->reviewed_by)->toBe($editor->id)
        ->and($relation->reviewed_at)->not->toBeNull();

    expect(Audit::where('auditable_id', $relation->id)->exists())->toBeTrue();
});

it('rejette une relation candidate sans la supprimer', function () {
    $editor = editeurPourTriage();
    $relation = DocumentRelation::factory()->candidate()->create();

    $this->actingAs($editor)
        ->postJson("/api/v1/relations/{$relation->id}/rejeter", ['commentaire' => 'Fausse piste'])
        ->assertOk();

    $relation->refresh();
    expect($relation->status)->toBe(DocumentRelation::STATUS_REJECTED)
        ->and($relation->reviewed_by)->toBe($editor->id)
        ->and($relation->commentaire)->toBe('Fausse piste');

    expect(DocumentRelation::count())->toBe(1);
});

it('refuse valider/rejeter à un rôle non éditorial', function () {
    $relation = DocumentRelation::factory()->candidate()->create();
    $lecteur = User::factory()->create();

    $this->actingAs($lecteur)
        ->postJson("/api/v1/relations/{$relation->id}/valider")
        ->assertForbidden();

    $this->actingAs($lecteur)
        ->postJson("/api/v1/relations/{$relation->id}/rejeter")
        ->assertForbidden();
});
