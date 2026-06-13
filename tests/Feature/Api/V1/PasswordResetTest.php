<?php

use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Couvre la réinitialisation de mot de passe par code OTP (API mobile) :
 * envoi du code, anti-énumération, expiration, réinitialisation effective
 * et révocation des jetons Sanctum.
 */
beforeEach(function () {
    Notification::fake();
    RateLimiter::clear('password_reset');
});

it('sends a six digit code to an existing account', function () {
    $user = User::factory()->create(['email' => 'avocat@mibeko.cg']);

    $response = $this->postJson('/api/v1/forgot-password', ['email' => 'avocat@mibeko.cg']);

    $response->assertOk()->assertJson(['success' => true]);
    Notification::assertSentTo($user, PasswordResetCodeNotification::class, function ($notification) {
        return preg_match('/^\d{6}$/', $notification->code) === 1;
    });
    expect(DB::table('password_reset_tokens')->where('email', 'avocat@mibeko.cg')->exists())->toBeTrue();
});

it('returns the same response for an unknown email without sending anything', function () {
    $response = $this->postJson('/api/v1/forgot-password', ['email' => 'inconnu@mibeko.cg']);

    $response->assertOk()->assertJson(['success' => true]);
    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->where('email', 'inconnu@mibeko.cg')->exists())->toBeFalse();
});

it('resets the password with a valid code and revokes existing tokens', function () {
    $user = User::factory()->create(['email' => 'avocat@mibeko.cg']);
    $user->createToken('Ancien appareil');

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('123456'),
        'created_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/reset-password', [
        'email' => 'avocat@mibeko.cg',
        'code' => '123456',
        'password' => 'nouveau-secret-9',
        'password_confirmation' => 'nouveau-secret-9',
    ]);

    $response->assertOk()->assertJson(['success' => true]);
    expect(Hash::check('nouveau-secret-9', $user->fresh()->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});

it('rejects an invalid code', function () {
    $user = User::factory()->create(['email' => 'avocat@mibeko.cg']);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('123456'),
        'created_at' => now(),
    ]);

    $this->postJson('/api/v1/reset-password', [
        'email' => 'avocat@mibeko.cg',
        'code' => '654321',
        'password' => 'nouveau-secret-9',
        'password_confirmation' => 'nouveau-secret-9',
    ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

it('rejects an expired code', function () {
    $user = User::factory()->create(['email' => 'avocat@mibeko.cg']);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('123456'),
        'created_at' => now()->subMinutes(20),
    ]);

    $this->postJson('/api/v1/reset-password', [
        'email' => 'avocat@mibeko.cg',
        'code' => '123456',
        'password' => 'nouveau-secret-9',
        'password_confirmation' => 'nouveau-secret-9',
    ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});
