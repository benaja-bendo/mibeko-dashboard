<?php

use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\Tag;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Role::findOrCreate('admin');
    Role::findOrCreate('user_pro');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->proUser = User::factory()->create();
    $this->proUser->assignRole('user_pro');
});

// ---------------------------------------------------------------------------
// Garde-fous d'accès
// ---------------------------------------------------------------------------

it('refuse l\'accès aux référentiels admin sans authentification', function () {
    $this->getJson('/api/v1/admin/document-types')->assertUnauthorized();
});

it('refuse l\'accès aux référentiels admin à un utilisateur non-admin', function () {
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/admin/document-types')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Types de documents
// ---------------------------------------------------------------------------

it('liste les types de documents avec leur compteur d\'usage', function () {
    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40]);
    LegalDocument::factory()->create(['type_code' => 'LOI']);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/document-types')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'LOI')
        ->assertJsonPath('data.0.documents_count', 1);
});

it('crée un type de document en normalisant le code en majuscules', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/document-types', [
            'code' => 'circ',
            'name' => 'Circulaire',
            'hierarchy_level' => 110,
        ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'CIRC')
        ->assertJsonPath('data.name', 'Circulaire');

    $this->assertDatabaseHas('document_types', [
        'code' => 'CIRC',
        'nom' => 'Circulaire',
        'niveau_hierarchique' => 110,
    ]);
});

it('refuse la création d\'un type avec un code déjà pris', function () {
    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/document-types', [
            'code' => 'loi',
            'name' => 'Autre loi',
            'hierarchy_level' => 50,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('met à jour le libellé d\'un type sans jamais changer son code', function () {
    $type = DocumentType::create(['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/document-types/{$type->code}", [
            'code' => 'HACK',
            'name' => 'Loi ordinaire',
            'hierarchy_level' => 42,
        ])
        ->assertOk()
        ->assertJsonPath('data.code', 'LOI')
        ->assertJsonPath('data.name', 'Loi ordinaire');

    $this->assertDatabaseHas('document_types', ['code' => 'LOI', 'nom' => 'Loi ordinaire']);
    $this->assertDatabaseMissing('document_types', ['code' => 'HACK']);
});

it('supprime un type de document inutilisé', function () {
    $type = DocumentType::create(['code' => 'TMP', 'nom' => 'Temporaire', 'niveau_hierarchique' => 200]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/document-types/{$type->code}")
        ->assertOk();

    $this->assertDatabaseMissing('document_types', ['code' => 'TMP']);
});

it('bloque la suppression d\'un type encore utilisé par des documents', function () {
    $type = DocumentType::create(['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40]);
    LegalDocument::factory()->create(['type_code' => 'LOI']);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/document-types/{$type->code}")
        ->assertStatus(409);

    $this->assertDatabaseHas('document_types', ['code' => 'LOI']);
});

// ---------------------------------------------------------------------------
// Institutions
// ---------------------------------------------------------------------------

it('crée une institution puis bloque sa suppression si elle est utilisée', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/institutions', ['name' => 'Ministère X', 'acronym' => 'MX'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ministère X')
        ->assertJsonPath('data.acronym', 'MX');

    $institution = Institution::where('nom', 'Ministère X')->firstOrFail();
    LegalDocument::factory()->create(['institution_id' => $institution->id]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/institutions/{$institution->id}")
        ->assertStatus(409);

    $this->assertDatabaseHas('institutions', ['id' => $institution->id]);
});

// ---------------------------------------------------------------------------
// Tags
// ---------------------------------------------------------------------------

it('crée un tag avec slug auto-généré et bloque sa suppression s\'il est attaché', function () {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/tags', ['name' => 'Droit du travail'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'droit-du-travail');

    $tag = Tag::where('name', 'Droit du travail')->firstOrFail();
    $doc = LegalDocument::factory()->create();
    $tag->legalDocuments()->attach($doc);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/tags/{$tag->id}")
        ->assertStatus(409);

    $this->assertDatabaseHas('tags', ['id' => $tag->id]);
});
