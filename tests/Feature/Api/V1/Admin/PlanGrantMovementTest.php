<?php

use App\Models\PlanGrant;
use App\Models\PlanGrantMovement;
use App\Models\User;
use App\Services\PlanGrantLedger;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->travelTo(now()->startOfSecond());
    Role::findOrCreate('admin');
    Role::findOrCreate('user_pro');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->customer = User::factory()->create();
    $this->grant = PlanGrant::factory()->for($this->customer)->create(['amount_fcfa' => 15000]);
    config(['audit.console' => true]);
});

it('protège la lecture et l\'écriture des mouvements', function () {
    $path = "/api/v1/admin/billing/grants/{$this->grant->id}/movements";
    $this->getJson($path)->assertUnauthorized();
    $this->actingAs($this->customer)->getJson($path)->assertForbidden();
    $this->postJson($path, [])->assertForbidden();
});

it('rembourse un octroi une seule fois avec motif et auteur, sans couper l\'accès par défaut', function () {
    $path = "/api/v1/admin/billing/grants/{$this->grant->id}/movements";
    $payload = ['type' => 'refund', 'amount_fcfa' => -5000, 'reason' => 'Paiement en double signalé par le client', 'reference_id' => 'RB-001'];

    $id = $this->actingAs($this->admin)->postJson($path, $payload)->assertOk()->json('data.id');
    $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $id);

    expect(PlanGrantMovement::count())->toBe(1);
    $this->assertDatabaseHas('plan_grant_movements', [
        'id' => $id, 'type' => 'refund', 'amount_fcfa' => -5000, 'created_by' => $this->admin->id,
    ]);
    $this->assertDatabaseHas('audits', ['auditable_type' => PlanGrantMovement::class, 'auditable_id' => $id, 'event' => 'created']);
    $this->grant->refresh();
    expect($this->grant->revoked_at)->toBeNull()
        ->and(PlanGrant::hasActive($this->customer))->toBeTrue();
});

it('coupe l\'accès seulement si revoke_access est explicitement vrai', function () {
    $path = "/api/v1/admin/billing/grants/{$this->grant->id}/movements";
    $this->actingAs($this->admin)->postJson($path, [
        'type' => 'refund', 'amount_fcfa' => -15000, 'reason' => 'Remboursement intégral', 'reference_id' => 'RB-002', 'revoke_access' => true,
    ])->assertOk();

    $this->grant->refresh();
    expect($this->grant->revoked_at)->not->toBeNull()
        ->and(PlanGrant::hasActive($this->customer))->toBeFalse();
});

it('refuse un remboursement positif et une correction à zéro', function () {
    $path = "/api/v1/admin/billing/grants/{$this->grant->id}/movements";
    $this->actingAs($this->admin)
        ->postJson($path, ['type' => 'refund', 'amount_fcfa' => 5000, 'reason' => 'x', 'reference_id' => 'RB-003'])
        ->assertUnprocessable();
    $this->postJson($path, ['type' => 'correction', 'amount_fcfa' => 0, 'reason' => 'x', 'reference_id' => 'RB-004'])
        ->assertUnprocessable();
    $this->postJson($path, ['type' => 'correction', 'amount_fcfa' => 1000, 'reason' => '', 'reference_id' => 'RB-005'])
        ->assertUnprocessable();
});

it('interdit de réutiliser une référence pour un autre octroi ou un autre montant', function () {
    $path = "/api/v1/admin/billing/grants/{$this->grant->id}/movements";
    $payload = ['type' => 'correction', 'amount_fcfa' => -1000, 'reason' => 'Erreur de saisie', 'reference_id' => 'CORR-001'];
    $this->actingAs($this->admin)->postJson($path, $payload)->assertOk();
    $this->postJson($path, [...$payload, 'amount_fcfa' => -2000])->assertUnprocessable();

    $other = PlanGrant::factory()->for($this->customer)->create();
    $this->postJson("/api/v1/admin/billing/grants/{$other->id}/movements", $payload)->assertUnprocessable();
});

it('liste les mouvements d\'un octroi du plus récent au plus ancien', function () {
    $this->actingAs($this->admin);
    $this->postJson("/api/v1/admin/billing/grants/{$this->grant->id}/movements", [
        'type' => 'correction', 'amount_fcfa' => -1000, 'reason' => 'a', 'reference_id' => 'M-1',
    ])->assertOk();
    $this->travel(1)->minute();
    $this->postJson("/api/v1/admin/billing/grants/{$this->grant->id}/movements", [
        'type' => 'refund', 'amount_fcfa' => -2000, 'reason' => 'b', 'reference_id' => 'M-2',
    ])->assertOk();

    $this->getJson("/api/v1/admin/billing/grants/{$this->grant->id}/movements")
        ->assertOk()
        ->assertJsonPath('data.movements.0.reference_id', 'M-2')
        ->assertJsonPath('data.movements.1.reference_id', 'M-1')
        ->assertJsonPath('data.net_amount_fcfa', -3000);
});

it('restitue le brut, les remboursements, le net et les écarts d\'une période', function () {
    $this->travelTo(now()->addYear()->startOfMonth()->addDays(5));
    $grantA = PlanGrant::factory()->for($this->customer)->create(['amount_fcfa' => 15000]);
    $grantB = PlanGrant::factory()->for($this->customer)->create(['amount_fcfa' => 20000]);
    app(PlanGrantLedger::class)->collect($grantA, 15000, null, now(), $this->admin);
    // Paiement partiel : saisi 20000, encaissé 12000 → écart.
    app(PlanGrantLedger::class)->collect($grantB, 12000, null, now(), $this->admin);
    app(PlanGrantLedger::class)->refund($grantA, -5000, 'Remboursement partiel', 'RB-PERIOD', $this->admin);
    app(PlanGrantLedger::class)->correction($grantA, 500, 'Frais bancaire recrédité', 'CORR-PERIOD', $this->admin);

    $this->actingAs($this->admin)->getJson('/api/v1/admin/billing/summary?month='.now()->format('Y-m'))
        ->assertOk()
        ->assertJsonPath('data.collected_amount_fcfa', 27000)
        ->assertJsonPath('data.refunded_amount_fcfa', 5000)
        ->assertJsonPath('data.corrections_amount_fcfa', 500)
        ->assertJsonPath('data.net_amount_fcfa', 22500)
        ->assertJsonPath('data.discrepancy_count', 1);
});

it('ne réécrit jamais une période déjà close quand un remboursement arrive plus tard', function () {
    $this->travelTo(now()->addYear()->startOfMonth()->addDays(5));
    $grant = PlanGrant::factory()->for($this->customer)->create(['amount_fcfa' => 15000]);
    app(PlanGrantLedger::class)->collect($grant, 15000, null, now(), $this->admin);
    $collectionMonth = now()->format('Y-m');

    $this->travelTo(now()->addMonthNoOverflow());
    app(PlanGrantLedger::class)->refund($grant, -5000, 'Remboursement le mois suivant', 'RB-LATE', $this->admin);

    $this->actingAs($this->admin);
    $this->getJson('/api/v1/admin/billing/summary?month='.$collectionMonth)
        ->assertOk()->assertJsonPath('data.collected_amount_fcfa', 15000)->assertJsonPath('data.refunded_amount_fcfa', 0);
    $this->getJson('/api/v1/admin/billing/summary?month='.now()->format('Y-m'))
        ->assertOk()->assertJsonPath('data.collected_amount_fcfa', 0)->assertJsonPath('data.refunded_amount_fcfa', 5000);
});
