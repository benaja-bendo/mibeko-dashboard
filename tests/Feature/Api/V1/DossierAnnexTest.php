<?php

use App\Models\Dossier;
use App\Models\DossierGeneratedDocument;
use App\Models\DossierPiece;
use App\Models\DossierReference;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

// ── Références ───────────────────────────────────────────────────────────────

it('adds a legal reference to the user own dossier', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $target = (string) Str::uuid();

    postJson("/api/v1/dossiers/{$dossier->id}/references", [
        'id' => $target,
        'type' => 'article',
        'title' => 'Article 1382 du Code civil',
        'breadcrumb' => 'Code civil › Responsabilité',
        'number' => 'Article 1382',
        'note' => 'Fondement responsabilité',
    ])
        ->assertCreated()
        ->assertJsonPath('data.id', $target)
        ->assertJsonPath('data.type', 'article')
        ->assertJsonPath('data.title', 'Article 1382 du Code civil')
        ->assertJsonPath('data.number', 'Article 1382');

    expect($dossier->references()->count())->toBe(1);
});

it('is idempotent when adding the same reference twice', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Sanctum::actingAs($user);

    $target = (string) Str::uuid();
    $payload = ['id' => $target, 'type' => 'article', 'title' => 'Article 9'];

    postJson("/api/v1/dossiers/{$dossier->id}/references", $payload)->assertCreated();

    postJson("/api/v1/dossiers/{$dossier->id}/references", [...$payload, 'title' => 'Ignoré'])
        ->assertOk()
        ->assertJsonPath('data.id', $target)
        ->assertJsonPath('data.title', 'Article 9');

    expect($dossier->references()->count())->toBe(1);
});

it('removes a reference by its target id', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    $reference = DossierReference::factory()->for($dossier)->create();
    Sanctum::actingAs($user);

    deleteJson("/api/v1/dossiers/{$dossier->id}/references/{$reference->target_id}")->assertOk();

    expect($dossier->references()->count())->toBe(0);
});

it('cannot add a reference to another user dossier', function () {
    $other = Dossier::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/v1/dossiers/{$other->id}/references", [
        'id' => (string) Str::uuid(),
        'type' => 'article',
        'title' => 'Tentative',
    ])->assertNotFound();
});

it('rejects an invalid reference type', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Sanctum::actingAs($user);

    postJson("/api/v1/dossiers/{$dossier->id}/references", [
        'id' => (string) Str::uuid(),
        'type' => 'inconnu',
        'title' => 'X',
    ])->assertUnprocessable();
});

// ── Pièces ───────────────────────────────────────────────────────────────────

it('adds a piece to the user own dossier', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Sanctum::actingAs($user);

    postJson("/api/v1/dossiers/{$dossier->id}/pieces", [
        'name' => 'assignation.pdf',
        'size' => 24_500,
        'mime' => 'application/pdf',
        'note' => 'Signifiée le 12/06',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'assignation.pdf')
        ->assertJsonPath('data.size', 24_500)
        ->assertJsonPath('data.mime', 'application/pdf');

    expect($dossier->pieces()->count())->toBe(1);
});

it('removes a piece', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    $piece = DossierPiece::factory()->for($dossier)->create();
    Sanctum::actingAs($user);

    deleteJson("/api/v1/dossiers/{$dossier->id}/pieces/{$piece->id}")->assertOk();

    expect($dossier->pieces()->count())->toBe(0);
});

it('cannot add a piece to another user dossier', function () {
    $other = Dossier::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/v1/dossiers/{$other->id}/pieces", [
        'name' => 'x.pdf',
        'size' => 10,
        'mime' => 'application/pdf',
    ])->assertNotFound();
});

// ── Documents générés ────────────────────────────────────────────────────────

it('adds a generated document to the user own dossier', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Sanctum::actingAs($user);

    postJson("/api/v1/dossiers/{$dossier->id}/documents", [
        'template_id' => 'mise-en-demeure',
        'template_name' => 'Mise en demeure',
        'title' => 'Mise en demeure — Dupont',
        'html' => '<h1>Mise en demeure</h1>',
    ])
        ->assertCreated()
        ->assertJsonPath('data.template_id', 'mise-en-demeure')
        ->assertJsonPath('data.title', 'Mise en demeure — Dupont')
        ->assertJsonPath('data.html', '<h1>Mise en demeure</h1>');

    expect($dossier->generatedDocuments()->count())->toBe(1);
});

it('removes a generated document', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    $document = DossierGeneratedDocument::factory()->for($dossier)->create();
    Sanctum::actingAs($user);

    deleteJson("/api/v1/dossiers/{$dossier->id}/documents/{$document->id}")->assertOk();

    expect($dossier->generatedDocuments()->count())->toBe(0);
});

it('cannot add a document to another user dossier', function () {
    $other = Dossier::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/v1/dossiers/{$other->id}/documents", [
        'template_id' => 't',
        'template_name' => 'T',
        'title' => 'X',
        'html' => '<p>x</p>',
    ])->assertNotFound();
});

// ── Chargement en une requête + cascade + isolation ──────────────────────────

it('returns annexes nested in the dossier resource', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    DossierReference::factory()->for($dossier)->create();
    DossierPiece::factory()->for($dossier)->create();
    DossierGeneratedDocument::factory()->for($dossier)->create();
    Sanctum::actingAs($user);

    getJson("/api/v1/dossiers/{$dossier->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.references')
        ->assertJsonCount(1, 'data.pieces')
        ->assertJsonCount(1, 'data.documents');
});

it('includes annexes in the full web listing', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    DossierReference::factory()->for($dossier)->create();
    Sanctum::actingAs($user);

    getJson('/api/v1/dossiers?full=1')
        ->assertOk()
        ->assertJsonCount(1, 'data.0.references');
});

it('cascade deletes annexes when the dossier is force deleted', function () {
    $dossier = Dossier::factory()->create();
    DossierReference::factory()->for($dossier)->create();
    DossierPiece::factory()->for($dossier)->create();
    DossierGeneratedDocument::factory()->for($dossier)->create();

    $dossier->forceDelete();

    expect(DossierReference::count())->toBe(0)
        ->and(DossierPiece::count())->toBe(0)
        ->and(DossierGeneratedDocument::count())->toBe(0);
});

it('cannot read or delete annexes of another user dossier', function () {
    $foreign = Dossier::factory()->create();
    $reference = DossierReference::factory()->for($foreign)->create();
    $piece = DossierPiece::factory()->for($foreign)->create();
    $document = DossierGeneratedDocument::factory()->for($foreign)->create();
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/v1/dossiers/{$foreign->id}")->assertNotFound();
    deleteJson("/api/v1/dossiers/{$foreign->id}/references/{$reference->target_id}")->assertNotFound();
    deleteJson("/api/v1/dossiers/{$foreign->id}/pieces/{$piece->id}")->assertNotFound();
    deleteJson("/api/v1/dossiers/{$foreign->id}/documents/{$document->id}")->assertNotFound();

    expect($foreign->references()->count())->toBe(1)
        ->and($foreign->pieces()->count())->toBe(1)
        ->and($foreign->generatedDocuments()->count())->toBe(1);
});
