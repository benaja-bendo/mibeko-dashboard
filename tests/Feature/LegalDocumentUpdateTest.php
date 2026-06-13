<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Permission::findOrCreate('documents.update');
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('permet à un éditeur de corriger le titre du document', function () {
    $document = LegalDocument::factory()->create(['titre_officiel' => 'code du travaille']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'titre_officiel' => 'Code du travail',
        ])
        ->assertOk()
        ->assertJsonPath('data.titre_officiel', 'Code du travail');

    expect($document->fresh()->titre_officiel)->toBe('Code du travail');
});

it('met à jour les métadonnées (NOR, dates, statut juridique)', function () {
    $document = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'reference_nor' => 'NOR-2026-042',
            'date_signature' => '1975-03-15',
            'statut' => 'abroge',
        ])
        ->assertOk();

    $fresh = $document->fresh();
    expect($fresh->reference_nor)->toBe('NOR-2026-042')
        ->and($fresh->date_signature->toDateString())->toBe('1975-03-15')
        ->and($fresh->statut)->toBe('abroge');
});

it('publie un document qui possède des articles', function () {
    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);
    Article::factory()->create(['document_id' => $document->id]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.curation_status', LegalDocument::STATUS_PUBLISHED);
});

it('refuse de publier un document sans article', function () {
    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('rejette un statut juridique invalide', function () {
    $document = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'statut' => 'annule',
        ])
        ->assertStatus(422);
});

it('refuse la mise à jour à un utilisateur sans rôle éditeur', function () {
    $document = LegalDocument::factory()->create();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'titre_officiel' => 'Tentative',
        ])
        ->assertForbidden();
});
