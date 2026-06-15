<?php

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('requires authentication for web dossier endpoints', function () {
    postJson('/api/v1/dossiers', [])->assertUnauthorized();
    getJson('/api/v1/dossiers/'.fake()->uuid())->assertUnauthorized();
});

it('creates a contentieux dossier with its initial echeances', function () {
    Sanctum::actingAs(User::factory()->create());

    postJson('/api/v1/dossiers', [
        'type' => 'contentieux',
        'title' => 'Licenciement abusif — M. Kabongo',
        'reference' => '2026-0142',
        'client' => 'Sté Minière du Katanga',
        'client_role' => 'defendeur',
        'adverse' => 'M. Jean Kabongo',
        'jurisdiction' => 'Tribunal du travail de Lubumbashi',
        'matiere' => 'Droit du travail',
        'echeances' => [
            ['type' => 'audience', 'title' => 'Audience de plaidoirie', 'due_date' => '2026-07-01'],
            ['type' => 'delai_procedure', 'title' => "Délai d'appel"],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'contentieux')
        ->assertJsonPath('data.title', 'Licenciement abusif — M. Kabongo')
        ->assertJsonPath('data.adverse', 'M. Jean Kabongo')
        ->assertJsonPath('data.matiere', 'Droit du travail')
        ->assertJsonCount(2, 'data.echeances');
});

it('lists only the user own dossiers with ?full=1', function () {
    $user = User::factory()->create();
    $mine = Dossier::factory()->for($user)->create(['name' => 'Mon dossier']);
    Dossier::factory()->create(); // autre utilisateur

    Sanctum::actingAs($user);

    getJson('/api/v1/dossiers?full=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.title', 'Mon dossier');
});

it('updates only the provided fields and can clear one with null', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create([
        'adverse_party' => 'Partie X',
        'status' => 'ouvert',
    ]);
    Sanctum::actingAs($user);

    patchJson("/api/v1/dossiers/{$dossier->id}", [
        'status' => 'en_cours',
        'adverse' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'en_cours')
        ->assertJsonPath('data.adverse', null);

    expect($dossier->refresh()->adverse_party)->toBeNull();
});

it('soft deletes a dossier (tombstone)', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Sanctum::actingAs($user);

    deleteJson("/api/v1/dossiers/{$dossier->id}")->assertOk();

    expect($dossier->refresh()->trashed())->toBeTrue();
    getJson("/api/v1/dossiers/{$dossier->id}")->assertNotFound();
});

it('hides dossiers of other users (404)', function () {
    $other = Dossier::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/v1/dossiers/{$other->id}")->assertNotFound();
    patchJson("/api/v1/dossiers/{$other->id}", ['title' => 'Détournement'])->assertNotFound();
    deleteJson("/api/v1/dossiers/{$other->id}")->assertNotFound();

    expect($other->refresh()->name)->not->toBe('Détournement');
});

it('rejects an invalid type or a missing title', function () {
    Sanctum::actingAs(User::factory()->create());

    postJson('/api/v1/dossiers', ['type' => 'inconnu', 'title' => 'X'])->assertUnprocessable();
    postJson('/api/v1/dossiers', ['type' => 'contentieux'])->assertUnprocessable();
});

it('keeps web-created dossiers compatible with the mobile sync', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $id = postJson('/api/v1/dossiers', [
        'type' => 'contentieux',
        'title' => 'Litige',
        'adverse' => 'M. Kabongo',
    ])->json('data.id');

    // Le dossier web apparaît dans l'enveloppe de sync mobile.
    getJson('/api/v1/dossiers')
        ->assertOk()
        ->assertJsonPath('data.dossiers.0.id', $id);

    // Une sync mobile postérieure renomme le dossier sans toucher aux champs web.
    postJson('/api/v1/dossiers/sync', [
        'dossiers' => [[
            'id' => $id,
            'name' => 'Renommé depuis le mobile',
            'updated_at' => 9_999_999_999_999,
        ]],
    ])->assertOk();

    $dossier = Dossier::findOrFail($id);
    expect($dossier->name)->toBe('Renommé depuis le mobile')   // LWW sur le champ partagé
        ->and($dossier->adverse_party)->toBe('M. Kabongo')      // champ web préservé
        ->and($dossier->type)->toBe('contentieux');
});
