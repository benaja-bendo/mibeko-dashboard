<?php

use App\Models\Dossier;
use App\Models\DossierEcheance;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('adds an echeance to the user own dossier', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Sanctum::actingAs($user);

    postJson("/api/v1/dossiers/{$dossier->id}/echeances", [
        'type' => 'audience',
        'title' => 'Audience de plaidoirie',
        'due_date' => '2026-07-01',
        'reminders' => [15, 7, 2, 0],
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Audience de plaidoirie')
        ->assertJsonPath('data.due_date', '2026-07-01')
        ->assertJsonPath('data.reminders', [15, 7, 2, 0]);

    expect($dossier->echeances()->count())->toBe(1);
});

it('cannot add an echeance to another user dossier', function () {
    $other = Dossier::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/v1/dossiers/{$other->id}/echeances", [
        'type' => 'audience',
        'title' => 'Tentative',
    ])->assertNotFound();
});

it('updates an echeance status', function () {
    $user = User::factory()->create();
    $echeance = DossierEcheance::factory()
        ->for(Dossier::factory()->for($user))
        ->create(['status' => 'a_venir']);
    Sanctum::actingAs($user);

    patchJson("/api/v1/echeances/{$echeance->id}", ['status' => 'fait'])
        ->assertOk()
        ->assertJsonPath('data.status', 'fait');
});

it('soft deletes an echeance', function () {
    $user = User::factory()->create();
    $echeance = DossierEcheance::factory()
        ->for(Dossier::factory()->for($user))
        ->create();
    Sanctum::actingAs($user);

    deleteJson("/api/v1/echeances/{$echeance->id}")->assertOk();

    expect($echeance->refresh()->trashed())->toBeTrue();
});

it('cannot touch echeances of another user', function () {
    $echeance = DossierEcheance::factory()->for(Dossier::factory())->create();
    Sanctum::actingAs(User::factory()->create());

    patchJson("/api/v1/echeances/{$echeance->id}", ['status' => 'fait'])->assertNotFound();
    deleteJson("/api/v1/echeances/{$echeance->id}")->assertNotFound();
});

it('rejects an invalid echeance type', function () {
    $user = User::factory()->create();
    $dossier = Dossier::factory()->for($user)->create();
    Sanctum::actingAs($user);

    postJson("/api/v1/dossiers/{$dossier->id}/echeances", [
        'type' => 'inconnu',
        'title' => 'X',
    ])->assertUnprocessable();
});
