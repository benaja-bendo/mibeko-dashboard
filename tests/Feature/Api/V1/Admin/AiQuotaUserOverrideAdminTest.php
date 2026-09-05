<?php

use App\Ai\AiUserQuotaTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * mibeko-dashboard#95 : override de quota IA par utilisateur, pensé pour une
 * vente manuelle (§11.3) — c'est l'admin qui saisit le chiffre.
 */
beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('user_pro');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->proUser = User::factory()->create();
    $this->proUser->assignRole('user_pro');

    $this->standard = User::factory()->create();
});

it('refuse l\'accès sans authentification', function () {
    $this->putJson("/api/v1/admin/users/{$this->standard->id}/ai-quota-override", ['limit' => 100])
        ->assertUnauthorized();
});

it('refuse l\'accès à un utilisateur non-admin', function () {
    $this->actingAs($this->proUser)
        ->putJson("/api/v1/admin/users/{$this->standard->id}/ai-quota-override", ['limit' => 100])
        ->assertForbidden();
});

it('pose un override qui devient la limite effective du compte', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/admin/users/{$this->standard->id}/ai-quota-override", [
            'limit' => 200,
            'note' => 'Vente manuelle du 05/09/2026',
        ])
        ->assertOk();

    $this->standard->refresh();
    expect(AiUserQuotaTier::resolve($this->standard)['limit'])->toBe(200);
    expect($this->standard->settings->ai_quota_override_note)->toBe('Vente manuelle du 05/09/2026');
});

it('crée la ligne de préférences si elle n\'existe pas encore (compte antérieur)', function () {
    expect($this->standard->settings)->toBeNull();

    $this->actingAs($this->admin)
        ->putJson("/api/v1/admin/users/{$this->standard->id}/ai-quota-override", ['limit' => 10])
        ->assertOk();

    expect($this->standard->settingsOrCreate()->ai_quota_override_limit)->toBe(10);
});

it('retire un override, le compte retombe sur son palier normal', function () {
    $this->standard->settingsOrCreate()->update(['ai_quota_override_limit' => 999]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/users/{$this->standard->id}/ai-quota-override")
        ->assertOk();

    $this->standard->refresh();
    expect(AiUserQuotaTier::resolve($this->standard)['limit'])->toBe((int) config('ai.quotas.standard.per_month'));
    expect($this->standard->settings->ai_quota_override_note)->toBeNull();
});

it('refuse un override invalide', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/admin/users/{$this->standard->id}/ai-quota-override", ['limit' => 'beaucoup'])
        ->assertStatus(422);
});
