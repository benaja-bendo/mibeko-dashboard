<?php

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    // Plusieurs requêtes par test (prise en charge + demande de correction +
    // publication) dépassent vite le throttle `api` réduit à 2/min en test.
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    $this->otherEditor = User::factory()->create();
    $this->otherEditor->assignRole('editor');

    $this->proUser = User::factory()->create();
    $this->proUser->assignRole('user_pro');
});

// ---------------------------------------------------------------------------
// Garde-fous d'accès
// ---------------------------------------------------------------------------

it('refuse la file de revue sans authentification', function () {
    $this->getJson('/api/v1/review-queue')->assertUnauthorized();
});

it('refuse la file de revue à un rôle non éditorial', function () {
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/review-queue')
        ->assertForbidden();
});

it('refuse la prise en charge à un rôle non éditorial', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'review']);

    $this->actingAs($this->proUser)
        ->postJson("/api/v1/legal-documents/{$document->id}/claim")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Liste & priorité
// ---------------------------------------------------------------------------

it('ne liste que les documents en revue par défaut, triés par priorité', function () {
    $bloque = LegalDocument::factory()->create([
        'curation_status' => 'review',
        'curation_status_changed_at' => now()->subDay(),
    ]);
    CurationFlag::create([
        'document_id' => $bloque->id,
        'type_probleme' => 'article_vide',
        'severity' => CurationFlag::SEVERITY_BLOCKING,
        'resolved' => false,
    ]);

    $ancien = LegalDocument::factory()->create([
        'curation_status' => 'review',
        'curation_status_changed_at' => now()->subWeek(),
    ]);

    LegalDocument::factory()->create(['curation_status' => 'draft']);
    LegalDocument::factory()->create(['curation_status' => 'published']);

    $response = $this->actingAs($this->editor)
        ->getJson('/api/v1/review-queue')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($response->json('data.0.id'))->toBe($bloque->id)
        ->and($response->json('data.0.blocking_flags_count'))->toBe(1)
        ->and($response->json('data.1.id'))->toBe($ancien->id);
});

it('filtre la file de revue par statut de curation', function () {
    LegalDocument::factory()->create(['curation_status' => 'draft']);
    LegalDocument::factory()->create(['curation_status' => 'review']);

    $this->actingAs($this->editor)
        ->getJson('/api/v1/review-queue?curation_status=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.curation_status', 'draft');
});

it('filtre la file de revue sur les documents non assignés', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'review']);
    $assigne = LegalDocument::factory()->create([
        'curation_status' => 'review',
        'assigned_to' => $this->editor->id,
    ]);

    $this->actingAs($this->editor)
        ->getJson('/api/v1/review-queue?assigned_to=unassigned')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $document->id);
});

// ---------------------------------------------------------------------------
// Prise en charge / relâche — droits serveur et conflits d'édition
// ---------------------------------------------------------------------------

it('prend en charge un document non assigné', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'review']);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/claim")
        ->assertOk()
        ->assertJsonPath('data.assigned_to', $this->editor->id)
        ->assertJsonPath('data.assignee.name', $this->editor->name);

    expect($document->refresh()->assigned_to)->toBe($this->editor->id);
    expect($document->assigned_at)->not->toBeNull();
});

it('est idempotent quand on reprend un document déjà pris par soi-même', function () {
    $document = LegalDocument::factory()->create([
        'curation_status' => 'review',
        'assigned_to' => $this->editor->id,
        'assigned_at' => now()->subHour(),
    ]);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/claim")
        ->assertOk();
});

it('refuse la prise en charge d\'un document déjà assigné à un autre éditeur (conflit)', function () {
    $document = LegalDocument::factory()->create([
        'curation_status' => 'review',
        'assigned_to' => $this->otherEditor->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/claim")
        ->assertStatus(409);

    expect($document->refresh()->assigned_to)->toBe($this->otherEditor->id);
});

it('permet au titulaire de relâcher son document', function () {
    $document = LegalDocument::factory()->create([
        'curation_status' => 'review',
        'assigned_to' => $this->editor->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/release")
        ->assertOk()
        ->assertJsonPath('data.assigned_to', null);

    expect($document->refresh()->assigned_to)->toBeNull();
    expect($document->assigned_at)->toBeNull();
});

it('refuse à un autre éditeur non-admin de relâcher un document qui n\'est pas le sien', function () {
    $document = LegalDocument::factory()->create([
        'curation_status' => 'review',
        'assigned_to' => $this->otherEditor->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/release")
        ->assertForbidden();

    expect($document->refresh()->assigned_to)->toBe($this->otherEditor->id);
});

it('permet à un administrateur de forcer la relâche d\'un document assigné à un autre', function () {
    $document = LegalDocument::factory()->create([
        'curation_status' => 'review',
        'assigned_to' => $this->editor->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/legal-documents/{$document->id}/release")
        ->assertOk();

    expect($document->refresh()->assigned_to)->toBeNull();
});

// ---------------------------------------------------------------------------
// Demande de correction — tracée, bloque la publication
// ---------------------------------------------------------------------------

it('transmet une demande de correction tracée', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'review']);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/correction-requests", [
            'description' => 'La date de signature ne correspond pas au PDF source.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.source', CurationFlag::SOURCE_HUMAN)
        ->assertJsonPath('data.severity', CurationFlag::SEVERITY_BLOCKING)
        ->assertJsonPath('data.created_by', $this->editor->name);

    $flag = CurationFlag::where('document_id', $document->id)->first();
    expect($flag->type_probleme)->toBe('correction_demandee');
    expect($flag->created_by)->toBe($this->editor->id);
    expect($flag->resolved)->toBeFalse();
});

it('rejette une demande de correction sans motif', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'review']);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/correction-requests", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('description');
});

it('bloque la publication tant que la demande de correction n\'est pas résolue', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'review']);
    Article::factory()->create(['document_id' => $document->id]);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/correction-requests", [
            'description' => 'Vérifier la source avant publication.',
        ])
        ->assertCreated();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['curation_status' => 'published'])
        ->assertStatus(422);

    expect($document->refresh()->curation_status)->toBe('review');

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => 'published',
            'force' => true,
        ])
        ->assertOk();

    expect($document->refresh()->curation_status)->toBe('published');
});
