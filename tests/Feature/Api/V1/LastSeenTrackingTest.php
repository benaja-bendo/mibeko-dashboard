<?php

use App\Models\User;

/**
 * mibeko-dashboard#129 : `UpdateLastSeen` ne mettait à jour `last_seen_at` que
 * sur le groupe de middleware `web` — jamais traversé par mibeko-front, qui
 * s'authentifie en Bearer Sanctum sur `/api/v1/*`. Ces tests couvrent le
 * câblage sur le groupe `api` et l'ordre (après `auth:sanctum`, qui résout
 * l'utilisateur authentifié) plutôt que la logique de `UpdateLastSeen`
 * elle-même, déjà couverte ailleurs.
 */
it('met à jour last_seen_at lors d\'un appel API authentifié en Bearer', function () {
    $user = User::factory()->create(['last_seen_at' => null]);

    $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($user->refresh()->last_seen_at)->not->toBeNull();
});

it('ne met pas à jour last_seen_at si moins de deux minutes se sont écoulées', function () {
    $lastSeen = now()->subMinute();
    $user = User::factory()->create(['last_seen_at' => $lastSeen]);

    $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

    expect($user->refresh()->last_seen_at->timestamp)->toBe($lastSeen->timestamp);
});
