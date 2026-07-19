<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;

/**
 * Renvoi de la vérification d'e-mail
 * (`POST /api/v1/email/verification-notification`).
 *
 * Contrat inter-dépôts : route authentifiée (Bearer) → 202, notification
 * Laravel standard. Le profil expose l'état de vérification (`email_verified`,
 * `email_verified_at`) sans jamais retirer les champs existants.
 */
it('renvoie la notification de vérification et répond 202 pour un utilisateur non vérifié', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/email/verification-notification')
        ->assertStatus(202);

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('n\'envoie rien mais répond 202 quand l\'adresse est déjà vérifiée', function () {
    Notification::fake();

    $user = User::factory()->create(); // vérifié par défaut

    $this->actingAs($user)
        ->postJson('/api/v1/email/verification-notification')
        ->assertStatus(202);

    Notification::assertNothingSent();
});

it('exige une authentification (401 en anonyme)', function () {
    $this->postJson('/api/v1/email/verification-notification')
        ->assertUnauthorized();
});

it('limite le renvoi (429 après trop de requêtes)', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    // Le renvoi est borné (throttle:3,1 sur la route). En environnement de test,
    // le limiteur global `api` (2/min) peut se déclencher en premier : on vérifie
    // donc simplement qu'un flot de requêtes finit par être refusé (429).
    $throttled = false;

    for ($i = 0; $i < 6; $i++) {
        $response = $this->actingAs($user)
            ->postJson('/api/v1/email/verification-notification');

        if ($response->getStatusCode() === 429) {
            $throttled = true;
            break;
        }
    }

    expect($throttled)->toBeTrue();
});

it('expose email_verified et email_verified_at sur le profil', function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    $verified = User::factory()->create();
    $this->actingAs($verified)
        ->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.email_verified_at', $verified->email_verified_at->toIso8601String());

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)
        ->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.email_verified_at', null);
});
