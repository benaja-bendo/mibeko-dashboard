<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\PublicationChecklist;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * dashboard#119 — PublicationGuardrail : critère de provenance, preuves de
 * validation retrouvables, et invalidation d'une validation devenue
 * obsolète après modification.
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

    // Plusieurs tests enchaînent plusieurs requêtes PATCH sur le même
    // utilisateur — même précaution que PublicationGovernanceTest.
    $this->withoutMiddleware(ThrottleRequests::class);
});

/** Document publiable par défaut : article, date connue, provenance connue (défaut factory). */
function checklistDocument(array $attributes = []): LegalDocument
{
    $document = LegalDocument::factory()->create(array_merge([
        'curation_status' => LegalDocument::STATUS_REVIEW,
        'date_entree_vigueur' => '2020-01-01',
    ], $attributes));

    Article::factory()->create(['document_id' => $document->id]);

    return $document;
}

// ---------------------------------------------------------------------------
// Critère de provenance
// ---------------------------------------------------------------------------

it('refuse de publier sans provenance ni confirmation explicite', function () {
    $document = checklistDocument(['metadata' => null]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('autorise de publier sans provenance si son absence est confirmée explicitement', function () {
    $document = checklistDocument(['metadata' => null]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
            'provenance_inconnue' => true,
        ])
        ->assertOk();

    $fresh = $document->fresh();
    expect($fresh->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED)
        ->and($fresh->provenance_inconnue)->toBeTrue();
});

it('force=true n\'outrepasse pas le critère de provenance', function () {
    $document = checklistDocument(['metadata' => null]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
            'force' => true,
        ])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('applique le critère de provenance en masse aussi', function () {
    $sansProvenance = checklistDocument(['metadata' => null]);
    $avecProvenance = checklistDocument();

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$sansProvenance->id, $avecProvenance->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 1)
        ->assertJsonPath('data.skipped_count', 1)
        ->assertJsonPath('data.skipped.0.motif', 'provenance non renseignée et absence non assumée');

    expect($sansProvenance->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW)
        ->and($avecProvenance->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('bulkUpdate accepte la confirmation « provenance inconnue » pour tout le lot et la persiste', function () {
    $premier = checklistDocument(['metadata' => null]);
    $second = checklistDocument(['metadata' => null]);

    $this->actingAs($this->editor)
        ->patchJson('/api/v1/legal-documents/bulk', [
            'ids' => [$premier->id, $second->id],
            'action' => 'set_curation_status',
            'value' => LegalDocument::STATUS_PUBLISHED,
            'provenance_inconnue' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated_count', 2)
        ->assertJsonPath('data.skipped_count', 0);

    foreach ([$premier, $second] as $document) {
        $fresh = $document->fresh();
        expect($fresh->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED)
            ->and($fresh->provenance_inconnue)->toBeTrue();
    }
});

// ---------------------------------------------------------------------------
// Preuves de validation retrouvables
// ---------------------------------------------------------------------------

it('enregistre une preuve « passed » après une publication réussie, retrouvable par l\'API', function () {
    $document = checklistDocument();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertOk();

    $response = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/publication-checklists")
        ->assertOk();

    expect($response->json('data.0.outcome'))->toBe('passed')
        ->and($response->json('data.0.target_status'))->toBe(LegalDocument::STATUS_PUBLISHED)
        ->and($response->json('data.0.actor.id'))->toBe($this->editor->id)
        ->and($response->json('data.0.criteria.has_article'))->toBeTrue()
        ->and($response->json('data.0.criteria.provenance_ok'))->toBeTrue();
});

it('enregistre une preuve « blocked » quand la publication est refusée', function () {
    $document = checklistDocument(['metadata' => null]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertStatus(422);

    $response = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/publication-checklists")
        ->assertOk();

    expect($response->json('data.0.outcome'))->toBe('blocked')
        ->and($response->json('data.0.criteria.provenance_ok'))->toBeFalse();
});

it('enregistre une preuve « forced » quand force=true outrepasse un flag bloquant', function () {
    $document = checklistDocument();
    CurationFlag::create([
        'document_id' => $document->id,
        'type_probleme' => 'article_manquant',
        'description' => 'Article(s) 2 absent(s).',
        'resolved' => false,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
            'force' => true,
        ])
        ->assertOk();

    $response = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/publication-checklists")
        ->assertOk();

    expect($response->json('data.0.outcome'))->toBe('forced');
});

it('ne laisse aucune preuve « passed » mensongère quand la transition elle-même est refusée', function () {
    // draft → published est interdit par la machine à états, indépendamment
    // du garde-fou : la preuve ne doit jamais prétendre qu'un contrôle a eu
    // lieu pour une publication qui n'a jamais abouti.
    $document = checklistDocument(['curation_status' => LegalDocument::STATUS_DRAFT]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => LegalDocument::STATUS_PUBLISHED])
        ->assertStatus(422);

    expect(PublicationChecklist::where('document_id', $document->id)->count())->toBe(0);
});

it('refuse de consulter les preuves de validation à un rôle non éditorial', function () {
    $document = checklistDocument();
    $sansRole = User::factory()->create();

    $this->actingAs($sansRole)
        ->getJson("/api/v1/legal-documents/{$document->id}/publication-checklists")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Invalidation d'une validation devenue obsolète
// ---------------------------------------------------------------------------

it('repasse en review quand le titre d\'un document validated change', function () {
    $document = checklistDocument(['curation_status' => LegalDocument::STATUS_VALIDATED]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['titre_officiel' => 'Nouveau titre après relecture'])
        ->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('repasse en review quand la provenance d\'un document validated change', function () {
    $document = checklistDocument(['curation_status' => LegalDocument::STATUS_VALIDATED]);

    $document->update(['metadata' => ['source_url' => 'https://sgg.cg/x', 'fetched_at' => now()->toIso8601String()]]);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('ne repasse pas en review quand seule l\'assignation change', function () {
    $document = checklistDocument(['curation_status' => LegalDocument::STATUS_VALIDATED]);

    $document->update(['assigned_to' => $this->editor->id, 'assigned_at' => now()]);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_VALIDATED);
});

it('n\'interfère pas avec une transition explicite de curation_status dans la même écriture', function () {
    // validated → published avec un titre modifié DANS LA MÊME requête :
    // c'est une transition explicite, couverte par son propre garde-fou —
    // le hook d'invalidation ne doit pas la court-circuiter en silence.
    $document = checklistDocument(['curation_status' => LegalDocument::STATUS_VALIDATED]);

    $document->update([
        'titre_officiel' => 'Titre corrigé au moment de publier',
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
    ]);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('repasse en review quand le contenu d\'un article change après validated', function () {
    $document = checklistDocument(['curation_status' => LegalDocument::STATUS_REVIEW]);
    $article = $document->articles()->first();
    $version = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Texte initial.',
    ]);

    $document->update(['curation_status' => LegalDocument::STATUS_VALIDATED]);

    // Relu depuis la base plutôt que réutilisé en mémoire : `wasRecentlyCreated`
    // ne se réinitialise pas à `false` sur une deuxième sauvegarde de la MÊME
    // instance PHP (comportement Eloquent), ce que le flux réel de correction
    // (ArticleController, version relue depuis une requête) ne reproduit
    // jamais — chaque requête HTTP hydrate une instance fraîche.
    ArticleVersion::find($version->id)->update(['contenu_texte' => 'Texte corrigé après la validation.']);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('ne repasse pas en review pour la toute première version d\'un article (structuration initiale)', function () {
    $document = checklistDocument(['curation_status' => LegalDocument::STATUS_VALIDATED]);
    $article = $document->articles()->first();

    // Création, pas modification : ne doit jamais être lue comme une
    // correction postérieure à la validation.
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Première version.',
    ]);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_VALIDATED);
});
