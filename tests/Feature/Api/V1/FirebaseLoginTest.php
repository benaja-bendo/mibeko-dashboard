<?php

use App\Models\User;
use Kreait\Firebase\Auth\UserRecord;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Spatie\Permission\Models\Role;

/**
 * Login Firebase (audit P0.7) : le jeton vérifié doit porter
 * `email_verified === true`, sinon un attaquant pourrait créer un compte
 * Firebase avec l'email d'autrui (non vérifié) et prendre la main sur le
 * compte Mibeko correspondant — `firstOrCreate` lie les comptes par email.
 */

/** Construit un faux jeton Firebase vérifié portant les claims fournis. */
function fakeFirebaseToken(array $claims): UnencryptedToken
{
    $token = Mockery::mock(UnencryptedToken::class);
    $token->shouldReceive('claims')->andReturn(new DataSet($claims, ''));

    return $token;
}

it('refuse un jeton firebase dont l\'email n\'est pas vérifié', function () {
    $auth = Mockery::mock(FirebaseAuth::class);
    $auth->shouldReceive('verifyIdToken')->once()->andReturn(fakeFirebaseToken([
        'sub' => 'firebase-uid-1',
        'email_verified' => false,
    ]));
    $auth->shouldNotReceive('getUser');

    Firebase::shouldReceive('auth')->andReturn($auth);

    $this->postJson('/api/v1/auth/firebase', [
        'id_token' => 'jeton-factice',
        'device_name' => 'Pixel 8',
    ])
        ->assertStatus(403)
        ->assertJsonPath('success', false);

    expect(User::count())->toBe(0);
});

it('accepte un jeton firebase dont l\'email est vérifié', function () {
    Role::findOrCreate('mobile_user');

    $auth = Mockery::mock(FirebaseAuth::class);
    $auth->shouldReceive('verifyIdToken')->once()->andReturn(fakeFirebaseToken([
        'sub' => 'firebase-uid-1',
        'email_verified' => true,
    ]));
    $auth->shouldReceive('getUser')->once()->with('firebase-uid-1')->andReturn(
        UserRecord::fromResponseData([
            'localId' => 'firebase-uid-1',
            'email' => 'citoyen@mibeko.cg',
            'emailVerified' => true,
            'displayName' => 'Citoyen Test',
            'createdAt' => '1700000000000',
        ]),
    );

    Firebase::shouldReceive('auth')->andReturn($auth);

    $this->postJson('/api/v1/auth/firebase', [
        'id_token' => 'jeton-factice',
        'device_name' => 'Pixel 8',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.email', 'citoyen@mibeko.cg');

    expect(User::where('email', 'citoyen@mibeko.cg')->exists())->toBeTrue();
});
