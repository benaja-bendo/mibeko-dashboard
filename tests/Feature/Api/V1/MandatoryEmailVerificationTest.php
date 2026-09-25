<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Le limiteur global `api` est très bas en test : plusieurs appels par test
    // le déclencheraient avant la règle éprouvée ici.
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * Lien de vérification tel que l'e-mail réel le construit (durée de validité
 * comprise), pour éprouver la configuration par le comportement.
 */
function lienDeVerification(User $user): string
{
    Notification::fake();
    $user->sendEmailVerificationNotification();

    $url = null;
    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user, &$url) {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    return $url;
}

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

it('garde le lien de l’e-mail valable 48 heures', function () {
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);
    $url = lienDeVerification($user);

    $this->travel(47)->hours();

    $this->get($url)
        ->assertOk()
        ->assertSee('Adresse e-mail vérifiée');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('explique en français qu’un lien expiré doit être redemandé', function () {
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);
    $url = lienDeVerification($user);

    $this->travel(49)->hours();

    $this->get($url)
        ->assertForbidden()
        ->assertSee('Ce lien a expiré')
        ->assertSee("Renvoyer l'e-mail de vérification", false)
        ->assertDontSee('Invalid signature');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('confirme l’adresse déjà vérifiée même depuis un lien expiré', function () {
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);
    $url = lienDeVerification($user);
    $user->markEmailAsVerified();

    $this->travel(49)->hours();

    $this->get($url)
        ->assertOk()
        ->assertSee('Adresse e-mail vérifiée');
});

it('refuse un lien falsifié sans rien vérifier', function (string $alteration) {
    $user = User::factory()->unverified()->create(['email_verification_required' => true]);
    $url = lienDeVerification($user);

    $falsifie = match ($alteration) {
        'signature' => preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url),
        'empreinte' => URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1('autre@example.test'),
        ]),
    };

    $this->get($falsifie)
        ->assertForbidden()
        ->assertSee("Ce lien n'est pas valide", false);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->with(['signature', 'empreinte']);
