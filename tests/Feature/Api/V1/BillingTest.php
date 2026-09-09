<?php

use App\Models\PlanGrant;
use App\Models\User;
use App\Services\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('returns a billing overview with plans and graceful defaults', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/billing');

    $response->assertStatus(200)
        ->assertJsonPath('data.subscription.status', 'none')
        ->assertJsonPath('data.payment_method', null)
        ->assertJsonCount(2, 'data.plans')
        ->assertJsonStructure([
            'data' => [
                'subscription' => ['status', 'plan_name', 'renews_at', 'on_grace_period'],
                'invoices',
                'billing_info' => ['company', 'rccm', 'tax_id', 'address'],
                'plans',
                'stripe_enabled',
            ],
        ]);

    // Sans clé Stripe en test, le paiement est désactivé.
    expect($response->json('data.stripe_enabled'))->toBeFalse()
        ->and($response->json('data.invoices'))->toBe([]);
});

it('updates legal billing information', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/v1/billing/info', [
        'company' => 'Cabinet Mibeko SARL',
        'rccm' => 'CD/KIN/RCCM/22-B-1234',
        'tax_id' => 'A1234567X',
        'address' => 'Av. du Palais, Kinshasa',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.billing_info.company', 'Cabinet Mibeko SARL')
        ->assertJsonPath('data.billing_info.rccm', 'CD/KIN/RCCM/22-B-1234');

    expect($user->settingsOrCreate()->fresh()->billing_info['tax_id'])->toBe('A1234567X');
});

it('refuses checkout when stripe is not configured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/billing/checkout', ['plan' => 'pro_monthly'])
        ->assertStatus(503);
});

it('refuses the billing portal when stripe is not configured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/billing/portal')->assertStatus(503);
});

it('blocks billing endpoints for guests', function () {
    $this->getJson('/api/v1/billing')->assertStatus(401);
});

it('montre le Pro manuel et son échéance sans inventer un abonnement Stripe', function () {
    $user = User::factory()->create();
    $grant = PlanGrant::factory()->for($user)->create(['notes' => 'Note interne confidentielle']);
    $this->actingAs($user)->getJson('/api/v1/billing')->assertOk()
        ->assertJsonPath('data.effective_plan', 'pro')
        ->assertJsonPath('data.subscription.status', 'none')
        ->assertJsonPath('data.manual_subscription.id', $grant->id)
        ->assertJsonMissing(['notes' => 'Note interne confidentielle']);
    $this->travelTo($grant->ends_at);
    $this->getJson('/api/v1/billing')->assertOk()
        ->assertJsonPath('data.effective_plan', 'libre')->assertJsonPath('data.manual_subscription', null);
    $this->getJson('/api/v1/billing/manual-grants')->assertJsonPath('data.0.status', 'ended');
});

it('isole les historiques client et ne révèle pas les notes ni les auteurs internes', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    PlanGrant::factory()->for($user)->create();
    PlanGrant::factory()->for($other)->create();
    app(CreditLedger::class)->purchase($user, 20, 'Motif interne');
    app(CreditLedger::class)->purchase($other, 50);
    $this->actingAs($user)->getJson('/api/v1/billing/manual-grants')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/billing/credits')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.amount', 20)->assertJsonMissing(['reason' => 'Motif interne']);
    $this->getJson('/api/v1/billing')->assertJsonPath('data.credit_balance', 20);
});
