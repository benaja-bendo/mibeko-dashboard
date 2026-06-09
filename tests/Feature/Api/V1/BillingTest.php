<?php

use App\Models\User;
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
