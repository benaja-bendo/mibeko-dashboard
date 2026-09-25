<?php

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Le limiteur global `api` est très bas en test : plusieurs appels par test
    // le déclencheraient avant la règle éprouvée ici.
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('bloque les fonctionnalités des nouveaux comptes tant que l’adresse e-mail n’est pas vérifiée', function () {
    config(['auth.verification.enforce' => true]);
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.email_verification_required', true);
});

it('valide l’adresse depuis le lien signé puis ouvre les fonctionnalités du compte', function () {
    config(['auth.verification.enforce' => true]);
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);
    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(30),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
    );

    $this->get($url)
        ->assertOk()
        ->assertSee('Adresse e-mail vérifiée');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($user->fresh())
        ->getJson('/api/v1/me/entitlements')
        ->assertOk();
});

it('ne bloque pas rétroactivement un compte historique non vérifié', function () {
    config(['auth.verification.enforce' => true]);
    $user = User::factory()->unverified()->create(['email_verification_required' => false]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk();
});

it('laisse passer un nouveau compte non vérifié tant que le blocage est suspendu', function () {
    // Réglage par défaut depuis le 25/09/2026 (mibeko-dashboard#206).
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk();

    // Les clients ne bloquent que sur cette valeur : elle doit suivre le serveur.
    $this->actingAs($user)
        ->getJson('/api/v1/profile')
        ->assertJsonPath('data.email_verification_required', false);

    $this->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertJsonPath('data.user.email_verification_required', false);

    expect($user->fresh()->email_verification_required)->toBeTrue();
});

it('annonce l’obligation au client dès l’inscription quand le blocage est actif', function () {
    config(['auth.verification.enforce' => true]);
    Notification::fake();
    Role::findOrCreate('mobile_user');

    $this->postJson('/api/v1/register', [
        'name' => 'Nouvelle recrue',
        'email' => 'recrue@example.test',
        'password' => 'motdepasse-solide',
        'password_confirmation' => 'motdepasse-solide',
        'device_name' => 'Mobile Device',
    ])->assertSuccessful()
        ->assertJsonPath('data.user.email_verification_required', true);
});
