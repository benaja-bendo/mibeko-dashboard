<?php

use App\Models\Device;
use App\Models\User;

/**
 * mibeko-dashboard#12 : `devices.user_id` NULL sur 100 % des lignes en
 * production. Le rattachement existe dans le contrôleur depuis le 30/07
 * (commit 1044634) mais n'était couvert par aucun test — rien ne garantissait
 * qu'il survive à une refonte de l'authentification.
 */
it('rattache l\'appareil au compte quand la requête porte un jeton', function () {
    $user = User::factory()->create();
    // Vrai jeton en en-tête, jamais `actingAs()` : la route est HORS du groupe
    // `auth:sanctum` et le contrôleur lit `user('sanctum')`. `actingAs()` pose
    // l'utilisateur sur le garde par défaut et masquerait une panne du garde
    // sanctum — la leçon de l'audit d'attribution du 16/08 (#47).
    $token = $user->createToken('iPhone de test')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/devices/register', [
            'device_id' => 'device-abc',
            'push_token' => 'token-fcm-abc',
            'platform' => 'android',
        ])->assertOk();

    expect(Device::where('device_id', 'device-abc')->sole()->user_id)->toBe($user->id);
});

it('accepte un enregistrement anonyme sans propriétaire', function () {
    // La route est publique par choix documenté : l'app pousse son jeton au
    // démarrage, avant toute connexion, et un invité reçoit la veille générale.
    $this->postJson('/api/v1/devices/register', [
        'device_id' => 'device-invite',
        'push_token' => 'token-fcm-invite',
        'platform' => 'ios',
    ])->assertOk();

    expect(Device::where('device_id', 'device-invite')->sole()->user_id)->toBeNull();
});

it('ne détache jamais un appareil déjà rattaché lors d\'un ré-enregistrement anonyme', function () {
    // L'app peut renvoyer son jeton avant que la session ne soit restaurée :
    // sans cette garde, le lien se perdrait à chaque démarrage — et avec lui
    // les préférences de veille de son propriétaire.
    $user = User::factory()->create();
    $token = $user->createToken('iPhone de test')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/devices/register', [
            'device_id' => 'device-abc',
            'push_token' => 'token-fcm-abc',
            'platform' => 'android',
        ])->assertOk();

    $this->postJson('/api/v1/devices/register', [
        'device_id' => 'device-abc',
        'push_token' => 'token-fcm-renouvele',
        'platform' => 'android',
    ])->assertOk();

    $device = Device::where('device_id', 'device-abc')->sole();

    expect($device->user_id)->toBe($user->id)
        ->and($device->push_token)->toBe('token-fcm-renouvele');
});
