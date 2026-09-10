<?php

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

// Le rate limiter `api` est volontairement bas en testing (2/min) ; on le neutralise
// car ces tests valident la logique métier, pas le throttling (couvert ailleurs).
beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

// ── Compte / profil ────────────────────────────────────────────────────────

it('returns the full account payload with settings', function () {
    $user = User::factory()->create(['name' => 'Me Tshala']);

    $response = $this->actingAs($user)->getJson('/api/v1/profile');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Me Tshala')
        ->assertJsonPath('data.settings.locale', 'fr')
        ->assertJsonPath('data.settings.timezone', 'Africa/Brazzaville')
        ->assertJsonStructure([
            'data' => ['id', 'email', 'roles', 'permissions', 'security' => ['two_factor_enabled'], 'settings'],
        ]);
});

it('updates personal information including the extended profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/v1/profile', [
        'name' => 'Nouveau Nom',
        'phone' => '+242068000000',
        // mibeko-dashboard#98 : liste fermée depuis le 06/09/2026.
        'profession' => 'Professionnel du droit',
        'company' => 'Cabinet Mibeko',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.name', 'Nouveau Nom')
        ->assertJsonPath('data.profile.company', 'Cabinet Mibeko');

    expect($user->fresh()->mobileProfile->profession)->toBe('Professionnel du droit');
});

it('refuse une profession hors de la liste fermée', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile', ['profession' => 'Avocat'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('profession');
});

it('changes the password and revokes other sessions', function () {
    $user = User::factory()->create();
    $current = $user->createToken('current')->plainTextToken;
    $user->createToken('other');

    $response = $this->withToken($current)->putJson('/api/v1/profile/password', [
        'current_password' => 'password',
        'password' => 'new-strong-password',
        'password_confirmation' => 'new-strong-password',
    ]);

    $response->assertStatus(200);
    expect($user->fresh()->tokens()->count())->toBe(1);
});

it('rejects a password change with a wrong current password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile/password', [
        'current_password' => 'wrong',
        'password' => 'new-strong-password',
        'password_confirmation' => 'new-strong-password',
    ])->assertStatus(422);
});

// ── Préférences ──────────────────────────────────────────────────────────────

it('updates display preferences', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile/preferences', [
        'locale' => 'en',
        'timezone' => 'Europe/Paris',
        'date_format' => 'Y-m-d',
    ])->assertStatus(200)->assertJsonPath('data.locale', 'en');

    expect($user->settingsOrCreate()->timezone)->toBe('Europe/Paris');
});

it('rejects an invalid locale', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile/preferences', [
        'locale' => 'de',
    ])->assertStatus(422);
});

it('replaces the notification preferences matrix', function () {
    $user = User::factory()->create();

    $preferences = [
        'extraction_update' => ['email' => false, 'push' => true, 'in_app' => true],
        'new_document' => ['email' => true, 'push' => false, 'in_app' => true],
        'share' => ['email' => true, 'push' => false, 'in_app' => true],
        'legal_alert' => ['email' => true, 'push' => true, 'in_app' => true],
        'billing' => ['email' => true, 'push' => false, 'in_app' => true],
        'system' => ['email' => true, 'push' => false, 'in_app' => true],
        '_frequency' => 'daily',
    ];

    $this->actingAs($user)->putJson('/api/v1/profile/notification-preferences', [
        'preferences' => $preferences,
    ])->assertStatus(200)
        ->assertJsonPath('data.notification_preferences._frequency', 'daily')
        ->assertJsonPath('data.notification_preferences.extraction_update.email', false);
});

/**
 * mibeko-dashboard#121 : une ligne `user_settings` enregistrée avant l'ajout
 * du type `billing` ne porte pas cette clé — le front indexe chaque type
 * sans filet et plantait sur cette matrice partielle (constaté en
 * vérification manuelle le 10/09/2026, avant ce correctif).
 */
it('complète une matrice de préférences enregistrée avant l\'ajout d\'un type', function () {
    $user = User::factory()->create();
    $user->settings()->create([
        ...UserSetting::defaults(),
        'notification_preferences' => [
            'extraction_update' => ['email' => true, 'push' => false, 'in_app' => true],
            'new_document' => ['email' => true, 'push' => true, 'in_app' => true],
            'share' => ['email' => true, 'push' => false, 'in_app' => true],
            'legal_alert' => ['email' => true, 'push' => false, 'in_app' => true],
            'system' => ['email' => true, 'push' => false, 'in_app' => true],
            '_frequency' => 'instant',
        ],
    ]);

    $this->actingAs($user)->getJson('/api/v1/profile')
        ->assertStatus(200)
        ->assertJsonPath('data.settings.notification_preferences.billing.email', true)
        ->assertJsonPath('data.settings.notification_preferences.billing.push', false)
        ->assertJsonPath('data.settings.notification_preferences.extraction_update.push', false);
});

it('rejects an incomplete notification matrix', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile/notification-preferences', [
        'preferences' => ['_frequency' => 'instant'],
    ])->assertStatus(422);
});

it('records consent timestamps and audits the change', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile/consents', [
        'marketing' => true,
    ])->assertStatus(200)->assertJsonPath('data.consents.marketing', true);

    $settings = $user->settingsOrCreate()->fresh();
    expect($settings->marketing_consent)->toBeTrue()
        ->and($settings->marketing_consent_at)->not->toBeNull();

    $this->assertDatabaseHas('audits', [
        'auditable_type' => UserSetting::class,
        'event' => 'updated',
    ]);
});

// ── Sécurité : 2FA ───────────────────────────────────────────────────────────

it('runs the full two-factor enable/confirm/disable flow', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    // Activation
    $enable = $this->actingAs($user)->postJson('/api/v1/profile/two-factor', [
        'current_password' => 'password',
    ]);
    $enable->assertStatus(200)->assertJsonStructure(['data' => ['svg', 'otpauth_url', 'recovery_codes']]);
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    // Confirmation avec un vrai code TOTP
    $secret = decrypt($user->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($user)->postJson('/api/v1/profile/two-factor/confirm', [
        'code' => $code,
    ])->assertStatus(200);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    // Désactivation
    $this->actingAs($user)->deleteJson('/api/v1/profile/two-factor', [
        'current_password' => 'password',
    ])->assertStatus(200);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('requires the current password to enable two-factor', function () {
    $user = User::factory()->withoutTwoFactor()->create();

    $this->actingAs($user)->postJson('/api/v1/profile/two-factor', [
        'current_password' => 'wrong',
    ])->assertStatus(422);
});

// ── Sécurité : sessions ──────────────────────────────────────────────────────

it('lists active sessions and flags the current one', function () {
    $user = User::factory()->create();
    $current = $user->createToken('current')->plainTextToken;
    $user->createToken('phone');

    $response = $this->withToken($current)->getJson('/api/v1/profile/sessions');

    $response->assertStatus(200)->assertJsonCount(2, 'data');
    $currentEntries = collect($response->json('data'))->where('is_current', true);
    expect($currentEntries)->toHaveCount(1);
});

it('revokes all other sessions but keeps the current one', function () {
    $user = User::factory()->create();
    $current = $user->createToken('current')->plainTextToken;
    $user->createToken('phone');
    $user->createToken('tablet');

    $this->withToken($current)->deleteJson('/api/v1/profile/sessions/others')->assertStatus(200);

    expect($user->fresh()->tokens()->count())->toBe(1);
});

it('cannot revoke the current session via the destroy endpoint', function () {
    $user = User::factory()->create();
    $token = $user->createToken('current');
    $plain = $token->plainTextToken;

    $this->withToken($plain)
        ->deleteJson("/api/v1/profile/sessions/{$token->accessToken->id}")
        ->assertStatus(422);
});

// ── Conformité RGPD ──────────────────────────────────────────────────────────

it('exports personal data as a downloadable json file', function () {
    $user = User::factory()->create(['name' => 'Export Me']);

    $response = $this->actingAs($user)->get('/api/v1/profile/export');

    $response->assertStatus(200)
        ->assertHeader('content-type', 'application/json')
        ->assertDownload();
});

it('deletes the account after password confirmation', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->deleteJson('/api/v1/profile', [
        'current_password' => 'password',
    ])->assertStatus(200);

    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

it('blocks every profile endpoint for guests', function () {
    $this->getJson('/api/v1/profile')->assertStatus(401);
    $this->getJson('/api/v1/profile/preferences')->assertStatus(401);
    $this->getJson('/api/v1/profile/sessions')->assertStatus(401);
});
