<?php

use App\Ai\AiUserQuotaTier;
use App\Models\AiQuotaTierSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * mibeko-dashboard#95 : réglage des quotas IA par palier depuis l'admin.
 */
beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('user_pro');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->proUser = User::factory()->create();
    $this->proUser->assignRole('user_pro');
});

it('refuse l\'accès sans authentification', function () {
    $this->getJson('/api/v1/admin/ai-quota-tiers')->assertUnauthorized();
});

it('refuse l\'accès à un utilisateur non-admin', function () {
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/admin/ai-quota-tiers')
        ->assertForbidden();
});

it('liste les trois paliers avec leur limite effective (config par défaut)', function () {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/ai-quota-tiers')
        ->assertOk();

    $tiers = collect($response->json('data'))->keyBy('tier');

    expect($tiers)->toHaveCount(3);
    expect($tiers['standard']['scope'])->toBe('month');
    expect($tiers['standard']['limit'])->toBe((int) config('ai.quotas.standard.per_month'));
    expect($tiers['standard']['source'])->toBe('config');
    expect($tiers['user_pro']['scope'])->toBe('day');
    expect($tiers['admin']['scope'])->toBe('day');
});

it('met à jour un palier, la limite effective change pour un vrai compte', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/ai-quota-tiers/user_pro', ['limit' => 300])
        ->assertOk()
        ->assertJsonPath('data.limit', 300)
        ->assertJsonPath('data.source', 'database');

    expect(AiUserQuotaTier::resolve($this->proUser)['limit'])->toBe(300);
});

it('refuse une limite invalide', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/ai-quota-tiers/standard', ['limit' => 0])
        ->assertStatus(422);
});

it('refuse un palier inconnu', function () {
    $this->actingAs($this->admin)
        ->putJson('/api/v1/admin/ai-quota-tiers/inconnu', ['limit' => 10])
        ->assertNotFound();
});

it('réinitialise un palier sur la valeur de config', function () {
    AiQuotaTierSetting::create(['tier' => 'standard', 'limit' => 999]);

    $this->actingAs($this->admin)
        ->deleteJson('/api/v1/admin/ai-quota-tiers/standard')
        ->assertOk()
        ->assertJsonPath('data.source', 'config')
        ->assertJsonPath('data.limit', (int) config('ai.quotas.standard.per_month'));

    expect(AiQuotaTierSetting::query()->where('tier', 'standard')->exists())->toBeFalse();
});
