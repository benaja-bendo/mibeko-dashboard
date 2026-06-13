<?php

use App\Models\LegalDocument;
use App\Models\OfficialJournal;
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

    foreach (['documents.update', 'documents.create'] as $permission) {
        Permission::findOrCreate($permission);
    }
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo(['documents.update', 'documents.create']);
    Role::findOrCreate('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

// ---------------------------------------------------------------------------
// Lecture (liste & détail)
// ---------------------------------------------------------------------------

it('masque les journaux non publiés au public', function () {
    OfficialJournal::factory()->create(['is_published' => false]);
    OfficialJournal::factory()->create(['is_published' => true]);

    $this->getJson('/api/v1/official-journals')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('liste tous les journaux pour un éditeur avec include_unpublished', function () {
    OfficialJournal::factory()->create(['is_published' => false]);
    OfficialJournal::factory()->create(['is_published' => true]);

    $this->actingAs($this->editor)
        ->getJson('/api/v1/official-journals?include_unpublished=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('ignore include_unpublished pour un utilisateur sans rôle', function () {
    OfficialJournal::factory()->create(['is_published' => false]);

    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->getJson('/api/v1/official-journals?include_unpublished=1')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('expose les documents non publiés du journal à l\'éditeur en vue manager', function () {
    $journal = OfficialJournal::factory()->create(['is_published' => false]);
    LegalDocument::factory()->create([
        'official_journal_id' => $journal->id,
        'curation_status' => 'draft',
    ]);

    // NB : JsonResource::withoutWrapping() est actif — pas d'enveloppe `data`
    // sur les ressources unitaires.
    $this->actingAs($this->editor)
        ->getJson("/api/v1/official-journals/{$journal->id}?include_unpublished=1")
        ->assertOk()
        ->assertJsonCount(1, 'legal_documents');

    // Le même détail reste introuvable pour le public (journal non publié).
    $this->getJson("/api/v1/official-journals/{$journal->id}")
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Administration (PATCH / DELETE)
// ---------------------------------------------------------------------------

it('permet à un éditeur de modifier les métadonnées et la visibilité', function () {
    $journal = OfficialJournal::factory()->create([
        'title' => 'JO mal nommé',
        'is_published' => false,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/official-journals/{$journal->id}", [
            'title' => 'Journal Officiel n° 12 - 2026',
            'number' => '12',
            'is_published' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Journal Officiel n° 12 - 2026');

    $fresh = $journal->fresh();
    expect($fresh->number)->toBe('12')
        ->and($fresh->is_published)->toBeTrue();
});

it('réserve la suppression d\'un journal aux administrateurs', function () {
    $journal = OfficialJournal::factory()->create();
    $document = LegalDocument::factory()->create(['official_journal_id' => $journal->id]);

    $this->actingAs($this->editor)
        ->deleteJson("/api/v1/official-journals/{$journal->id}")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/official-journals/{$journal->id}")
        ->assertOk();

    expect(OfficialJournal::find($journal->id))->toBeNull()
        ->and($document->fresh()->official_journal_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Traitement manuel des manquements d'extraction
// ---------------------------------------------------------------------------

it('crée manuellement un texte manquant rattaché au journal', function () {
    $journal = OfficialJournal::factory()->create();

    $response = $this->actingAs($this->editor)
        ->postJson('/api/v1/legal-documents', [
            'titre_officiel' => 'Décret n° 2026-101 oublié par l\'extraction',
            'official_journal_id' => $journal->id,
            'date_publication' => '2026-05-01',
        ])
        ->assertCreated()
        ->assertJsonPath('data.titre_officiel', 'Décret n° 2026-101 oublié par l\'extraction');

    $document = LegalDocument::find($response->json('data.id'));
    expect($document->official_journal_id)->toBe($journal->id)
        ->and($document->document_role)->toBe('FLUX')
        ->and($document->curation_status)->toBe(LegalDocument::STATUS_DRAFT)
        ->and($document->metadata['source'] ?? null)->toBe('manual');
});

it('crée un document FLUX même sans journal (STOCK réservé au pipeline)', function () {
    $response = $this->actingAs($this->editor)
        ->postJson('/api/v1/legal-documents', [
            'titre_officiel' => 'Loi isolée saisie à la main',
        ])
        ->assertCreated();

    expect(LegalDocument::find($response->json('data.id'))->document_role)->toBe('FLUX');
});

it('refuse de rattacher un document consolidé (STOCK) à un journal', function () {
    $journal = OfficialJournal::factory()->create();
    $document = LegalDocument::factory()->create([
        'document_role' => 'STOCK',
        'stock_code' => 'CODE-TRAVAIL',
        'consolidation_as_of' => '2026-01-01',
        'official_journal_id' => null,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'official_journal_id' => $journal->id,
        ])
        ->assertStatus(422);
});

it('rattache et détache un texte existant via PATCH official_journal_id', function () {
    $journal = OfficialJournal::factory()->create();
    $document = LegalDocument::factory()->create(['official_journal_id' => null]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'official_journal_id' => $journal->id,
        ])
        ->assertOk();
    expect($document->fresh()->official_journal_id)->toBe($journal->id);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'official_journal_id' => null,
        ])
        ->assertOk();
    expect($document->fresh()->official_journal_id)->toBeNull();
});

it('refuse la création de document à un utilisateur sans rôle', function () {
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->postJson('/api/v1/legal-documents', [
            'titre_officiel' => 'Tentative',
        ])
        ->assertForbidden();
});
