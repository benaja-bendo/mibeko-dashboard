<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

it('bloque les fonctionnalités des nouveaux comptes tant que l’adresse e-mail n’est pas vérifiée', function () {
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.email_verified', false);
});

it('valide l’adresse depuis le lien signé puis ouvre les fonctionnalités du compte', function () {
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);
    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(30),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
    );

    $this->get($url)
        ->assertOk()
        ->assertSee('Adresse e-mail vérifiée')
        ->assertSee(rtrim((string) config('app.frontend_url'), '/').'/auth/verifier-email', false);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($user->fresh())
        ->getJson('/api/v1/me/entitlements')
        ->assertOk();
});

it('ne bloque pas rétroactivement un compte historique non vérifié', function () {
    $user = User::factory()->unverified()->create(['email_verification_required' => false]);

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk();
});
