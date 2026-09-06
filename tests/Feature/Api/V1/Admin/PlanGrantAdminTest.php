<?php

use App\Models\PlanGrant;
use App\Models\User;
use App\Services\EntitlementsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * mibeko-dashboard#100 : accorder un abonnement Pro vendu à la main, pensé
 * pour une vente manuelle (§11.3) — c'est l'admin qui saisit l'ajustement
 * après un encaissement mobile money ou en espèces.
 */
beforeEach(function () {
    Role::findOrCreate('admin');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->standard = User::factory()->create();
});

it('refuse l\'accès sans authentification', function () {
    $this->postJson("/api/v1/admin/users/{$this->standard->id}/plan-grant", ['ends_at' => now()->addMonth()->toIso8601String()])
        ->assertUnauthorized();
});

it('refuse l\'accès à un utilisateur non-admin', function () {
    $this->actingAs($this->standard)
        ->postJson("/api/v1/admin/users/{$this->standard->id}/plan-grant", ['ends_at' => now()->addMonth()->toIso8601String()])
        ->assertForbidden();
});

it('accorde un abonnement Pro qui devient immédiatement effectif', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->standard->id}/plan-grant", [
            'ends_at' => now()->addMonth()->toIso8601String(),
            'amount_fcfa' => 15_000,
            'channel' => 'mobile_money',
            'reference' => 'MM-20260906-001',
        ])
        ->assertOk();

    $this->standard->refresh();
    expect(app(EntitlementsResolver::class)->resolve($this->standard)['plan'])->toBe('pro');

    $grant = PlanGrant::where('user_id', $this->standard->id)->sole();
    expect($grant->amount_fcfa)->toBe(15_000);
    expect($grant->channel)->toBe('mobile_money');
    expect($grant->created_by)->toBe($this->admin->id);
});

it('refuse une échéance dans le passé', function () {
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/users/{$this->standard->id}/plan-grant", ['ends_at' => now()->subDay()->toIso8601String()])
        ->assertStatus(422);
});

it('révoque un octroi actif avant son échéance', function () {
    PlanGrant::factory()->for($this->standard)->create();
    expect(app(EntitlementsResolver::class)->resolve($this->standard)['plan'])->toBe('pro');

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/users/{$this->standard->id}/plan-grant")
        ->assertOk();

    $this->standard->refresh();
    expect(app(EntitlementsResolver::class)->resolve($this->standard)['plan'])->toBe('libre');
});

it('signale l\'absence d\'octroi actif à révoquer', function () {
    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/users/{$this->standard->id}/plan-grant")
        ->assertStatus(404);
});

it('expose l\'octroi actif dans la fiche détaillée admin', function () {
    PlanGrant::factory()->for($this->standard)->create(['channel' => 'especes']);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/users/{$this->standard->id}")
        ->assertOk()
        ->assertJsonPath('data.plan_grant.channel', 'especes');
});
