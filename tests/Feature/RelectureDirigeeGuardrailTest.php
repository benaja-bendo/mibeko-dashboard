<?php

use App\Models\Article;
use App\Models\DocumentControleRun;
use App\Models\DocumentRelecturePreuve;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * dashboard#142 — PublicationGuardrail étendu à la preuve de relecture
 * dirigée : critère de non-régression (documents jamais contrôlés, ou dont
 * le contrôle est propre, publiables comme avant) et critère nouveau
 * (réserves ouvertes exigent une preuve enregistrée), chemin unitaire et en
 * masse — croisé avec le critère de clôture explicite du ticket.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Role::findOrCreate('editor');
    Permission::findOrCreate('documents.update');
    Role::findByName('editor')->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    $this->withoutMiddleware(ThrottleRequests::class);
});

/** Document publiable par défaut (article, date, provenance connus). */
function relectureDocument(array $attributes = []): LegalDocument
{
    $document = LegalDocument::factory()->create(array_merge([
        'curation_status' => LegalDocument::STATUS_REVIEW,
        'date_entree_vigueur' => '2020-01-01',
    ], $attributes));

    Article::factory()->create(['document_id' => $document->id]);

    return $document;
}

function publierViaApi(User $editor, LegalDocument $document)
{
    return test()->actingAs($editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED]);
}

// ---------------------------------------------------------------------------
// Non-régression : rien ne change pour un document sans réserve
// ---------------------------------------------------------------------------

it('publie normalement un document jamais contrôlé par le jeu v3 (aucun run)', function () {
    $document = relectureDocument();

    publierViaApi($this->editor, $document)->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('publie normalement un document dont le dernier contrôle est ok, sans preuve de relecture', function () {
    $document = relectureDocument();
    DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'resultat' => DocumentControleRun::RESULTAT_OK,
    ]);

    publierViaApi($this->editor, $document)->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

// ---------------------------------------------------------------------------
// Nouveau critère : réserves ouvertes exigent une preuve
// ---------------------------------------------------------------------------

it('refuse de publier (requête directe, chemin unitaire) un document en échec sans preuve de relecture', function () {
    $document = relectureDocument();
    DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'resultat' => DocumentControleRun::RESULTAT_ECHEC,
    ]);

    publierViaApi($this->editor, $document)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Le dernier contrôle de conformité de ce document porte des réserves : une relecture dirigée (points d\'observation, sondage) doit être enregistrée avant de publier.');

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('publie normalement un document en échec dont la preuve de relecture couvre CE run précis', function () {
    $document = relectureDocument();
    $run = DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'resultat' => DocumentControleRun::RESULTAT_ECHEC,
    ]);
    DocumentRelecturePreuve::factory()->create([
        'document_id' => $document->id,
        'document_controle_run_id' => $run->id,
        'actor_id' => $this->editor->id,
    ]);

    publierViaApi($this->editor, $document)->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('refuse toujours si la preuve enregistrée couvre un run PLUS ANCIEN que le dernier', function () {
    $document = relectureDocument();
    $ancienRun = DocumentControleRun::factory()->create([
        'document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC, 'date' => now()->subDay(),
    ]);
    DocumentRelecturePreuve::factory()->create([
        'document_id' => $document->id, 'document_controle_run_id' => $ancienRun->id,
    ]);
    // Un nouveau passage du jeu de détecteurs déclasse la preuve précédente.
    DocumentControleRun::factory()->create([
        'document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC, 'date' => now(),
    ]);

    publierViaApi($this->editor, $document)->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('n\'est jamais outrepassé par force=true, contrairement au critère des flags', function () {
    $document = relectureDocument();
    DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'resultat' => DocumentControleRun::RESULTAT_ECHEC,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
            'force' => true,
        ])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('applique le critère en masse aussi, avec un motif court dédié', function () {
    $sansPreuve = relectureDocument();
    DocumentControleRun::factory()->create(['document_id' => $sansPreuve->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC]);

    $avecPreuve = relectureDocument();
    $run = DocumentControleRun::factory()->create(['document_id' => $avecPreuve->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC]);
    DocumentRelecturePreuve::factory()->create(['document_id' => $avecPreuve->id, 'document_controle_run_id' => $run->id]);

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$sansPreuve->id, $avecPreuve->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 1)
        ->assertJsonPath('data.skipped_count', 1)
        ->assertJsonPath('data.skipped.0.motif', 'relecture dirigée non enregistrée');

    expect($sansPreuve->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW)
        ->and($avecPreuve->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});
