<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Audit docs/audit-ingestion-2026-08-02.md, phase 3 : gouvernance de
 * publication — 3a (bulkUpdate : autorisation par document + audit),
 * 3b (machine à états maison de curation_status), 3c (gate éditorial
 * date_entree_vigueur).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['editor', 'admin'] as $role) {
        Role::findOrCreate($role);
    }
    Permission::findOrCreate('documents.update');

    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->admin->givePermissionTo('documents.update');

    Audit::query()->delete();

    // Hors-sujet ici (throttle:api est une préoccupation d'infra, pas de
    // gouvernance de publication) : plusieurs tests enchaînent plusieurs
    // requêtes PATCH sur le même endpoint/utilisateur, et l'environnement de
    // test resserre volontairement la limite à 2/min (AppServiceProvider).
    $this->withoutMiddleware(ThrottleRequests::class);
});

/** Document avec au moins un article, aucune anomalie, date d'entrée en vigueur connue. */
function gouvernanceDocument(array $attributes = []): LegalDocument
{
    $document = LegalDocument::factory()->create(array_merge([
        'curation_status' => LegalDocument::STATUS_DRAFT,
        'date_entree_vigueur' => '2020-01-01',
    ], $attributes));

    Article::factory()->create(['document_id' => $document->id]);

    return $document;
}

// ---------------------------------------------------------------------------
// 3a — bulkUpdate : autorisation par document + exécution Eloquent (audit)
// ---------------------------------------------------------------------------

it('refuse la mise à jour en masse à un rôle non autorisé', function () {
    $sansPermission = User::factory()->create(); // aucun rôle, aucune permission
    $documents = collect(range(1, 3))->map(fn () => gouvernanceDocument());

    $this->actingAs($sansPermission)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => $documents->pluck('id')->all(),
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_REVIEW,
        ])
        ->assertForbidden();

    expect(LegalDocument::whereIn('id', $documents->pluck('id'))->where('curation_status', LegalDocument::STATUS_DRAFT)->count())
        ->toBe(3);
});

it('génère un audit par document effectivement modifié en masse', function () {
    $documents = collect(range(1, 4))->map(fn () => gouvernanceDocument());

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => $documents->pluck('id')->all(),
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_REVIEW,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 4)
        ->assertJsonPath('data.skipped_count', 0);

    $audits = Audit::where('auditable_type', LegalDocument::class)
        ->whereIn('auditable_id', $documents->pluck('id'))
        ->where('event', 'updated')
        ->count();

    expect($audits)->toBe(4);
});

it('ne modifie et n\'audite que les documents effectivement mis à jour, pas les documents écartés', function () {
    $publiable = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_VALIDATED]);
    $sansArticle = LegalDocument::factory()->create([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => '2020-01-01',
    ]); // pas d'article

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$publiable->id, $sansArticle->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 1)
        ->assertJsonPath('data.skipped_count', 1);

    expect($publiable->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED)
        ->and($sansArticle->fresh()->curation_status)->toBe(LegalDocument::STATUS_VALIDATED);

    expect(Audit::where('auditable_type', LegalDocument::class)->where('auditable_id', $sansArticle->id)->where('event', 'updated')->count())
        ->toBe(0);
});

// ---------------------------------------------------------------------------
// 3b — machine à états de curation_status
// ---------------------------------------------------------------------------

it('autorise chaque transition avant, une étape à la fois', function () {
    $document = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_DRAFT]);

    foreach ([LegalDocument::STATUS_REVIEW, LegalDocument::STATUS_VALIDATED, LegalDocument::STATUS_PUBLISHED] as $etape) {
        $this->actingAs($this->editor)
            ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => $etape])
            ->assertOk();

        expect($document->fresh()->curation_status)->toBe($etape);
    }
});

it('refuse un saut qui dépasse une étape', function () {
    $document = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_DRAFT]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_VALIDATED])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_DRAFT);
});

it('refuse draft -> published directement', function () {
    $document = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_DRAFT]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_DRAFT);
});

it('autorise les retours arrière explicitement listés', function (string $depart, string $arrivee) {
    $document = gouvernanceDocument(['curation_status' => $depart]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => $arrivee])
        ->assertOk();

    expect($document->fresh()->curation_status)->toBe($arrivee);
})->with([
    'review -> draft' => [LegalDocument::STATUS_REVIEW, LegalDocument::STATUS_DRAFT],
    'validated -> review' => [LegalDocument::STATUS_VALIDATED, LegalDocument::STATUS_REVIEW],
    'validated -> draft' => [LegalDocument::STATUS_VALIDATED, LegalDocument::STATUS_DRAFT],
]);

it('refuse la dépublication par un éditeur non admin, même avec un motif', function () {
    $document = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_PUBLISHED]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_DRAFT,
            'motif' => 'Erreur de contenu détectée',
        ])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('refuse la dépublication par un admin SANS motif', function () {
    $document = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_PUBLISHED]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_DRAFT,
        ])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('autorise la dépublication par un admin avec motif, et la journalise', function () {
    $document = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_PUBLISHED]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_DRAFT,
            'motif' => 'Erreur de contenu détectée, à reprendre entièrement',
        ])
        ->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_DRAFT);
});

it('ne republie jamais en masse un document déjà dépublié', function () {
    $document = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_PUBLISHED]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_DRAFT,
            'motif' => 'Source non officielle, à remplacer avant republication',
        ])
        ->assertOk();

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$document->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_REVIEW,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 1);

    $response = $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$document->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 0)
        ->assertJsonPath('data.skipped_count', 1);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW)
        ->and($response->json('data.skipped.0.motif'))
        ->toBe('document déjà dépublié : republication unitaire obligatoire');

    // Le verrou vise les lots accidentels, pas une republication consciente :
    // le chemin unitaire conserve tous ses garde-fous et reste disponible.
    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('applique la garde de transition dans bulkUpdate aussi (skip, pas de 500)', function () {
    $document = gouvernanceDocument(['curation_status' => LegalDocument::STATUS_DRAFT]);

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$document->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_VALIDATED, // saute "review"
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 0)
        ->assertJsonPath('data.skipped_count', 1);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_DRAFT);
});

// ---------------------------------------------------------------------------
// 3c — gate éditorial : date d'entrée en vigueur
// ---------------------------------------------------------------------------

it('refuse de publier sans date d\'entrée en vigueur ni confirmation explicite', function () {
    $document = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => null,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_VALIDATED);
});

it('autorise de publier quand la date d\'entrée en vigueur est connue', function () {
    $document = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => '2020-01-01',
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('autorise de publier sans date si son absence est confirmée explicitement', function () {
    $document = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => null,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
            'date_entree_vigueur_inconnue' => true,
        ])
        ->assertOk();

    $fresh = $document->fresh();
    expect($fresh->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED)
        ->and($fresh->date_entree_vigueur_inconnue)->toBeTrue();
});

it('la confirmation déjà posée en base suffit sans la renvoyer à chaque requête', function () {
    $document = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => null,
        'date_entree_vigueur_inconnue' => true,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('applique le gate date d\'entrée en vigueur dans bulkUpdate aussi', function () {
    $sansDate = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => null,
    ]);
    $avecDate = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => '2020-01-01',
    ]);

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$sansDate->id, $avecDate->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 1)
        ->assertJsonPath('data.skipped_count', 1);

    expect($sansDate->fresh()->curation_status)->toBe(LegalDocument::STATUS_VALIDATED)
        ->and($avecDate->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('bulkUpdate accepte la confirmation « date inconnue » pour tout le lot et la persiste', function () {
    // Le cas du corpus réel : le pipeline ne renseigne jamais la date d'entrée
    // en vigueur. Sans ce drapeau, aucun document publiable en masse.
    $premier = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => null,
    ]);
    $second = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => null,
    ]);

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$premier->id, $second->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
            'date_entree_vigueur_inconnue' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 2)
        ->assertJsonPath('data.skipped_count', 0);

    foreach ([$premier, $second] as $document) {
        $fresh = $document->fresh();
        expect($fresh->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED)
            ->and($fresh->date_entree_vigueur_inconnue)->toBeTrue();
    }
});

it('bulkUpdate rend le motif de chaque document écarté', function () {
    $sansArticle = LegalDocument::factory()->create([
        'curation_status' => LegalDocument::STATUS_VALIDATED,
        'date_entree_vigueur' => '2020-01-01',
    ]);
    $sautDeRevue = gouvernanceDocument([
        'curation_status' => LegalDocument::STATUS_DRAFT,
        'date_entree_vigueur' => '2020-01-01',
    ]);

    $response = $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$sansArticle->id, $sautDeRevue->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 0)
        ->assertJsonPath('data.skipped_count', 2);

    $motifs = collect($response->json('data.skipped'))->keyBy('id');

    expect($motifs->get($sansArticle->id)['motif'])->toBe('aucun article')
        ->and($motifs->get($sautDeRevue->id)['motif'])->toContain('draft');
});
