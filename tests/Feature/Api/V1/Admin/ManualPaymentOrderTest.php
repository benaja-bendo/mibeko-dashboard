<?php

use App\Models\ManualPaymentOrder;
use App\Models\PlanGrant;
use App\Models\PlanGrantMovement;
use App\Models\User;
use App\Notifications\PlanGrantActivatedNotification;
use App\Services\EntitlementsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    config(['audit.console' => true]);
    Role::findOrCreate('admin');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->customer = User::factory()->create();
});

function orderPayload(string $key): array
{
    return [
        'idempotency_key' => $key,
        'amount_fcfa' => 15_000,
        'duration_months' => 2,
        'channel' => 'mobile_money',
        'payment_instructions' => 'Versez 15 000 FCFA au numéro confirmé par Mibeko.',
        'internal_notes' => 'Offre confirmée par téléphone.',
    ];
}

it('réserve la gestion des commandes aux administrateurs', function () {
    $this->getJson('/api/v1/admin/billing/payment-orders')->assertUnauthorized();
    $this->actingAs($this->customer)->getJson('/api/v1/admin/billing/payment-orders')->assertForbidden();
});

it('crée une seule commande quand la même requête est rejouée', function () {
    $key = (string) Str::uuid();
    $path = "/api/v1/admin/users/{$this->customer->id}/payment-orders";

    $first = $this->actingAs($this->admin)->postJson($path, orderPayload($key))->assertOk();
    $this->postJson($path, orderPayload($key))
        ->assertOk()
        ->assertJsonPath('message', 'Commande déjà enregistrée.')
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(ManualPaymentOrder::count())->toBe(1);
    expect($first->json('data.reference'))->toStartWith('MBK-');
});

it('refuse de réutiliser une clé pour une autre commande', function () {
    $key = (string) Str::uuid();
    $path = "/api/v1/admin/users/{$this->customer->id}/payment-orders";
    $this->actingAs($this->admin)->postJson($path, orderPayload($key))->assertOk();

    $this->postJson($path, [...orderPayload($key), 'amount_fcfa' => 20_000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');
});

it('vérifie puis active une commande une seule fois', function () {
    Notification::fake();
    $order = ManualPaymentOrder::factory()->for($this->customer)->create([
        'created_by' => $this->admin->id,
        'status' => ManualPaymentOrder::STATUS_PAYMENT_DECLARED,
        'payment_reference' => 'MM-20260910-001',
        'payment_declared_at' => now(),
        'duration_months' => 2,
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/billing/payment-orders/{$order->id}/verify")
        ->assertOk()
        ->assertJsonPath('data.status', ManualPaymentOrder::STATUS_VERIFYING);
    $first = $this->postJson("/api/v1/admin/billing/payment-orders/{$order->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', ManualPaymentOrder::STATUS_ACTIVATED);
    $this->postJson("/api/v1/admin/billing/payment-orders/{$order->id}/activate")
        ->assertOk()
        ->assertJsonPath('message', 'Commande déjà activée.')
        ->assertJsonPath('data.plan_grant_id', $first->json('data.plan_grant_id'));

    expect(PlanGrant::count())->toBe(1);
    $grant = PlanGrant::sole();
    expect($grant->user_id)->toBe($this->customer->id)
        ->and($grant->reference)->toBe('MM-20260910-001')
        ->and($grant->starts_at->diffInMonths($grant->ends_at))->toBe(2.0)
        ->and(app(EntitlementsResolver::class)->resolve($this->customer)['plan'])->toBe('pro');

    // mibeko-dashboard#121 : confirmation envoyée une seule fois, même si
    // l'activation est rejouée (idempotence côté commande, pas seulement côté octroi).
    Notification::assertSentTimes(PlanGrantActivatedNotification::class, 1);
    Notification::assertSentTo($this->customer, PlanGrantActivatedNotification::class,
        fn (PlanGrantActivatedNotification $notification) => $notification->grant->is($grant));

    // mibeko-dashboard#122 : sans précision, l'encaissement réel suit le montant saisi de la commande.
    expect(PlanGrantMovement::where('plan_grant_id', $grant->id)->sole())
        ->type->toBe('collected')->amount_fcfa->toBe($order->amount_fcfa);
});

it('distingue le montant réellement encaissé du montant saisi lors de l\'activation', function () {
    $order = ManualPaymentOrder::factory()->for($this->customer)->create([
        'created_by' => $this->admin->id,
        'status' => ManualPaymentOrder::STATUS_VERIFYING,
        'payment_reference' => 'MM-20260910-PARTIEL',
        'amount_fcfa' => 15000,
    ]);

    $collectedAt = now()->subDay()->startOfSecond();
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/billing/payment-orders/{$order->id}/activate", [
            'collected_amount_fcfa' => 12000,
            'collected_at' => $collectedAt->toIso8601String(),
        ])
        ->assertOk();

    $grant = PlanGrant::sole();
    expect($grant->amount_fcfa)->toBe(15000);
    $movement = PlanGrantMovement::where('plan_grant_id', $grant->id)->sole();
    expect($movement->amount_fcfa)->toBe(12000)
        ->and($movement->manual_payment_order_id)->toBe($order->id)
        ->and($movement->occurred_at->equalTo($collectedAt))->toBeTrue();
});

it('interdit une activation sans vérification préalable', function () {
    $order = ManualPaymentOrder::factory()->for($this->customer)->create([
        'created_by' => $this->admin->id,
        'status' => ManualPaymentOrder::STATUS_PAYMENT_DECLARED,
        'payment_reference' => 'MM-20260910-002',
        'payment_declared_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/billing/payment-orders/{$order->id}/activate")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
    expect(PlanGrant::count())->toBe(0);
});

it('refuse une commande avec un motif visible du client', function () {
    $order = ManualPaymentOrder::factory()->for($this->customer)->create(['created_by' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/billing/payment-orders/{$order->id}/reject", [
            'reason' => 'Le canal choisi est temporairement indisponible.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', ManualPaymentOrder::STATUS_REJECTED)
        ->assertJsonPath('data.rejection_reason', 'Le canal choisi est temporairement indisponible.');

    $order->refresh();
    expect($order->resolved_by)->toBe($this->admin->id)
        ->and($order->rejected_at)->not->toBeNull();
});

it('journalise la création et les changements de statut', function () {
    $order = ManualPaymentOrder::factory()->for($this->customer)->create([
        'created_by' => $this->admin->id,
        'status' => ManualPaymentOrder::STATUS_PAYMENT_DECLARED,
        'payment_reference' => 'MM-AUDIT-001',
        'payment_declared_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/billing/payment-orders/{$order->id}/verify")
        ->assertOk();

    expect($order->audits()->where('event', 'updated')->exists())->toBeTrue();
});
