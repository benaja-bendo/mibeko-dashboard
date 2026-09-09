<?php

use App\Models\CreditLedgerEntry;
use App\Models\PlanGrant;
use App\Models\User;
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
    config(['audit.console' => true]);
});

it('protège toutes les lectures administratives', function (string $path) {
    $this->getJson('/api/v1/admin/billing/'.$path)->assertUnauthorized();
    $this->actingAs($this->customer)->getJson('/api/v1/admin/billing/'.$path)->assertForbidden();
})->with(['summary', 'grants', 'untracked', 'credits']);

it('calcule les échéances et distingue les montants inconnus', function () {
    PlanGrant::factory()->for($this->customer)->create(['ends_at' => now()->addDays(3), 'amount_fcfa' => 15000]);
    PlanGrant::factory()->for($this->customer)->create(['ends_at' => now()->addDays(20), 'amount_fcfa' => null]);
    PlanGrant::factory()->for($this->customer)->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(5), 'amount_fcfa' => 0]);
    PlanGrant::factory()->for($this->customer)->create(['created_at' => now()->subMonths(2), 'ends_at' => now()->subDay(), 'amount_fcfa' => 500]);
    $legacy = User::factory()->create();
    $legacy->assignRole('user_pro');
    $this->actingAs($this->admin)->getJson('/api/v1/admin/billing/summary')
        ->assertOk()->assertJsonPath('data.recorded_amount_fcfa', 15000)
        ->assertJsonPath('data.unpriced_grants', 1)
        ->assertJsonPath('data.expiring_7_days', 1)->assertJsonPath('data.expiring_30_days', 2)
        ->assertJsonPath('data.untracked_pro_accounts', 1);
    $this->getJson('/api/v1/admin/billing/grants?status=expiring_7')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/billing/untracked')->assertJsonPath('data.0.id', $legacy->id);
    $this->getJson('/api/v1/admin/billing/summary?month=invalid')->assertUnprocessable();
});

it('enregistre un achat une seule fois avec auteur et audit', function () {
    $path = "/api/v1/admin/users/{$this->customer->id}/credits";
    $payload = ['type' => 'purchase', 'amount' => 100, 'reason' => 'Encaissement vérifié', 'reference_id' => 'MM-001'];
    $id = $this->actingAs($this->admin)->postJson($path, $payload)->assertOk()->json('data.id');
    $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $id);
    expect(CreditLedgerEntry::count())->toBe(1);
    $this->assertDatabaseHas('credit_ledger_entries', ['id' => $id, 'created_by' => $this->admin->id]);
    $this->assertDatabaseHas('audits', ['auditable_type' => CreditLedgerEntry::class, 'auditable_id' => $id, 'event' => 'created', 'user_id' => $this->admin->id]);
    $this->postJson($path, [...$payload, 'amount' => 200])->assertUnprocessable();
    $this->postJson($path, [...$payload, 'reference_id' => 'MM-002', 'type' => 'correction', 'amount' => -20])->assertOk();
    $this->getJson($path)->assertJsonPath('data.balance', 80)->assertJsonCount(2, 'data.entries.data');
});

it('valide les crédits et interdit de réutiliser une référence pour un autre compte', function () {
    $path = "/api/v1/admin/users/{$this->customer->id}/credits";
    $payload = ['type' => 'purchase', 'amount' => 100, 'reason' => 'Vente', 'reference_id' => 'MM-003'];
    $this->actingAs($this->admin)->postJson($path, [...$payload, 'amount' => -1])->assertUnprocessable();
    $this->postJson($path, [...$payload, 'reason' => ' '])->assertUnprocessable();
    $this->postJson($path, [...$payload, 'type' => 'correction', 'amount' => 0])->assertUnprocessable();
    $this->postJson($path, $payload)->assertOk();
    $this->postJson("/api/v1/admin/users/{$this->admin->id}/credits", $payload)->assertUnprocessable();
});

it('rend une vente Pro rejouable sans double octroi', function () {
    $path = "/api/v1/admin/users/{$this->customer->id}/plan-grant";
    $payload = ['ends_at' => now()->addMonth()->toIso8601String(), 'amount_fcfa' => 15000, 'channel' => 'mobile_money', 'reference' => 'MM-004'];
    $id = $this->actingAs($this->admin)->postJson($path, $payload)->assertOk()->json('data.id');
    $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $id);
    expect(PlanGrant::count())->toBe(1);
    $this->postJson($path, [...$payload, 'amount_fcfa' => 30000])->assertUnprocessable();
    $this->postJson($path, [...$payload, 'reference' => null])->assertUnprocessable();
    $this->assertDatabaseHas('audits', ['auditable_type' => PlanGrant::class, 'auditable_id' => $id, 'event' => 'created']);
});

it('retire tous les octrois actifs après un renouvellement sans laisser un ancien accès', function () {
    PlanGrant::factory()->for($this->customer)->create(['ends_at' => now()->addDays(4)]);
    PlanGrant::factory()->for($this->customer)->create(['ends_at' => now()->addMonth()]);
    $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$this->customer->id}/plan-grant")->assertOk();
    expect(PlanGrant::hasActive($this->customer))->toBeFalse();
    expect(PlanGrant::count())->toBe(2);
});

it('attribue l\'audit de crédit au jeton Sanctum administratif', function () {
    $token = $this->admin->createToken('billing-test')->plainTextToken;
    $id = $this->withToken($token)->postJson("/api/v1/admin/users/{$this->customer->id}/credits", [
        'type' => 'purchase', 'amount' => 10, 'reason' => 'Vente vérifiée', 'reference_id' => 'SANCTUM-001',
    ])->assertOk()->json('data.id');
    $this->assertDatabaseHas('audits', ['auditable_id' => $id, 'user_id' => $this->admin->id]);
});
