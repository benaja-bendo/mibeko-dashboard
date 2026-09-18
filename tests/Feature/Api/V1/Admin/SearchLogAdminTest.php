<?php

use App\Models\SearchLog;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * mibeko-dashboard#111 : l'écran admin « requêtes fréquentes / sans résultat »
 * — deux agrégats en lecture seule sur `search_logs`.
 */
beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('user_pro');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->proUser = User::factory()->create();
    $this->proUser->assignRole('user_pro');
});

it('refuse les deux listes à un non-admin', function () {
    $this->actingAs($this->proUser)->getJson('/api/v1/admin/search-logs/top')->assertForbidden();
    $this->actingAs($this->proUser)->getJson('/api/v1/admin/search-logs/no-results')->assertForbidden();
});

it('classe les requêtes fréquentes par volume décroissant', function () {
    SearchLog::create(['query' => 'travail', 'results_count' => 5, 'surface' => 'library/search']);
    SearchLog::create(['query' => 'travail', 'results_count' => 5, 'surface' => 'library/search']);
    SearchLog::create(['query' => 'travail', 'results_count' => 5, 'surface' => 'library/search']);
    SearchLog::create(['query' => 'famille', 'results_count' => 2, 'surface' => 'library/search']);

    $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/search-logs/top')->assertOk();

    $data = $response->json('data');
    expect($data[0]['query'])->toBe('travail');
    expect($data[0]['volume'])->toBe(3);
    expect($data[1]['query'])->toBe('famille');
});

it('ne retourne que les requêtes sans résultat', function () {
    SearchLog::create(['query' => 'code du travail', 'results_count' => 0, 'surface' => 'library/search']);
    SearchLog::create(['query' => 'code du travail', 'results_count' => 0, 'surface' => 'library/search']);
    SearchLog::create(['query' => 'contrat', 'results_count' => 4, 'surface' => 'library/search']);

    $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/search-logs/no-results')->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['query'])->toBe('code du travail');
    expect($data[0]['volume'])->toBe(2);
});

it('ignore les recherches plus vieilles que la fenêtre demandée', function () {
    $old = SearchLog::create(['query' => 'ancien', 'results_count' => 0, 'surface' => 'library/search']);
    $old->timestamps = false;
    $old->created_at = now()->subDays(45);
    $old->save();

    $response = $this->actingAs($this->admin)->getJson('/api/v1/admin/search-logs/no-results?days=30')->assertOk();

    expect($response->json('data'))->toHaveCount(0);
});
