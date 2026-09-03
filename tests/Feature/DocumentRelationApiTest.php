<?php

use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * `POST /articles/{article}/relations` existait déjà, mais aucune route ne
 * permettait de créer une relation document-à-document pure (ex. une
 * Constitution qui en abroge une autre) — DocumentRelationController::store()
 * ne lit jamais `{article}`, mais sans route nue le seul moyen d'y arriver
 * était d'inventer un article sans rapport dans l'URL. `POST
 * /document-relations` couvre ce cas, même contrôleur (mibeko-dashboard#31).
 */
function editeurPourRelations(): User
{
    Role::findOrCreate('editor');

    $editor = User::factory()->create();
    $editor->assignRole('editor');

    return $editor;
}

it('crée une relation document-à-document via /document-relations', function () {
    $editor = editeurPourRelations();
    $source = LegalDocument::factory()->create(['curation_status' => 'published']);
    $target = LegalDocument::factory()->create(['curation_status' => 'published']);

    $response = $this->actingAs($editor)->postJson('/api/v1/document-relations', [
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
        'effective_date' => '2015-10-25',
        'commentaire' => 'Test',
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('document_relations', [
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
    ]);

    // effective_date validé par le contrôleur mais absent de $fillable a
    // longtemps disparu en silence à la création (create() ignore tout champ
    // hors $fillable, sans erreur) : régression verrouillée explicitement.
    expect(DocumentRelation::first()->effective_date->toDateString())->toBe('2015-10-25');
});

it('refuse une relation sans source ni cible', function () {
    $editor = editeurPourRelations();

    $this->actingAs($editor)->postJson('/api/v1/document-relations', [
        'relation_type' => 'ABROGE',
    ])->assertStatus(422);

    expect(DocumentRelation::count())->toBe(0);
});

it('refuse un appel non authentifié', function () {
    $source = LegalDocument::factory()->create();
    $target = LegalDocument::factory()->create();

    $this->postJson('/api/v1/document-relations', [
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'relation_type' => 'ABROGE',
    ])->assertUnauthorized();
});
