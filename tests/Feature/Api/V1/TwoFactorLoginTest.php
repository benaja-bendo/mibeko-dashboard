<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PragmaRX\Google2FA\Google2FA;

/**
 * Couvre le challenge 2FA du login API : un compte protégé ne peut pas
 * obtenir de jeton avec le seul mot de passe (le contournement mobile
 * historique est fermé).
 */
function makeTwoFactorUser(): User
{
    $user = User::factory()->withoutTwoFactor()->create([
        'email' => 'avocat@mibeko.cg',
        'password' => Hash::make('secret-passe-9'),
    ]);

    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user->fresh();
}

it('logs in normally when two factor is not enabled', function () {
    User::factory()->withoutTwoFactor()->create([
        'email' => 'avocat@mibeko.cg',
        'password' => Hash::make('secret-passe-9'),
    ]);

    $this->postJson('/api/v1/login', [
        'email' => 'avocat@mibeko.cg',
        'password' => 'secret-passe-9',
        'device_name' => 'Pixel 8',
    ])->assertOk()->assertJsonPath('success', true);
});

it('refuses password-only login when two factor is enabled', function () {
    makeTwoFactorUser();

    $response = $this->postJson('/api/v1/login', [
        'email' => 'avocat@mibeko.cg',
        'password' => 'secret-passe-9',
        'device_name' => 'Pixel 8',
    ]);

    $response->assertStatus(423)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors.two_factor_required', true);
});

it('logs in with a valid totp code', function () {
    $user = makeTwoFactorUser();
    $otp = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));

    $this->postJson('/api/v1/login', [
        'email' => 'avocat@mibeko.cg',
        'password' => 'secret-passe-9',
        'device_name' => 'Pixel 8',
        'code' => $otp,
    ])->assertOk()->assertJsonPath('success', true);
});

it('rejects an invalid totp code', function () {
    makeTwoFactorUser();

    $this->postJson('/api/v1/login', [
        'email' => 'avocat@mibeko.cg',
        'password' => 'secret-passe-9',
        'device_name' => 'Pixel 8',
        'code' => '000000',
    ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

it('logs in with a recovery code and consumes it', function () {
    $user = makeTwoFactorUser();
    $recoveryCode = $user->recoveryCodes()[0];

    $this->postJson('/api/v1/login', [
        'email' => 'avocat@mibeko.cg',
        'password' => 'secret-passe-9',
        'device_name' => 'Pixel 8',
        'recovery_code' => $recoveryCode,
    ])->assertOk()->assertJsonPath('success', true);

    expect($user->fresh()->recoveryCodes())->not->toContain($recoveryCode);
});
