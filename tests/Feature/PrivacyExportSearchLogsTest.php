<?php

use App\Models\SearchLog;
use App\Models\User;

/**
 * mibeko-dashboard#111 : l'export RGPD inclut les recherches du compte
 * courant, exclut celles d'un autre compte, et la suppression de compte
 * anonymise le journal plutôt que de le laisser pointer vers un compte
 * supprimé (le soft delete de `User` ne déclenche jamais `nullOnDelete()`).
 */
it('inclut les recherches du compte courant dans l\'export', function () {
    $user = User::factory()->create();
    SearchLog::create(['user_id' => $user->id, 'query' => 'travail', 'results_count' => 3, 'surface' => 'library/search']);

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['search_logs'])->toHaveCount(1);
    expect($payload['search_logs'][0]['query'])->toBe('travail');
});

it('exclut les recherches d\'un autre compte', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    SearchLog::create(['user_id' => $userA->id, 'query' => 'travail', 'results_count' => 3, 'surface' => 'library/search']);

    $response = $this->actingAs($userB)->get('/api/v1/profile/export');
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['search_logs'])->toHaveCount(0);
});

it('anonymise les recherches à la suppression du compte', function () {
    $user = User::factory()->create(['password' => bcrypt('mot-de-passe-courant')]);
    $log = SearchLog::create(['user_id' => $user->id, 'query' => 'travail', 'results_count' => 3, 'surface' => 'library/search']);

    $this->actingAs($user)
        ->deleteJson('/api/v1/profile', ['current_password' => 'mot-de-passe-courant'])
        ->assertOk();

    expect($log->refresh()->user_id)->toBeNull();
});
