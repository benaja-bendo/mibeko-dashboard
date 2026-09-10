<?php

use App\Models\ManualPaymentOrder;
use App\Models\PlanGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->customer = User::factory()->create();
    $this->otherCustomer = User::factory()->create();
    $this->admin = User::factory()->create();
});

it('ne montre au client que ses commandes et aucune note interne', function () {
    $order = ManualPaymentOrder::factory()->for($this->customer)->create([
        'created_by' => $this->admin->id,
        'internal_notes' => 'Relancer mardi, client sensible.',
    ]);
    ManualPaymentOrder::factory()->for($this->otherCustomer)->create(['created_by' => $this->admin->id]);

    $this->actingAs($this->customer)->getJson('/api/v1/billing/payment-orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonMissingPath('data.0.internal_notes')
        ->assertJsonMissingPath('data.0.creator');
});

it('déclare un paiement de façon idempotente et conserve le contrôle humain', function () {
    $order = ManualPaymentOrder::factory()->for($this->customer)->create(['created_by' => $this->admin->id]);
    $path = "/api/v1/billing/payment-orders/{$order->id}/declare";

    $this->actingAs($this->customer)->postJson($path, ['payment_reference' => ' mm-2401 '])
        ->assertOk()
        ->assertJsonPath('data.status', ManualPaymentOrder::STATUS_PAYMENT_DECLARED)
        ->assertJsonPath('data.payment_reference', 'MM-2401');
    $this->postJson($path, ['payment_reference' => 'MM-2401'])
        ->assertOk()
        ->assertJsonPath('message', 'Paiement déjà déclaré.');

    expect(PlanGrant::count())->toBe(0);
    expect(ManualPaymentOrder::whereKey($order->id)->value('payment_declared_at'))->not->toBeNull();
});

it('refuse une commande étrangère et une référence réutilisée', function () {
    $first = ManualPaymentOrder::factory()->for($this->customer)->create(['created_by' => $this->admin->id]);
    $second = ManualPaymentOrder::factory()->for($this->otherCustomer)->create(['created_by' => $this->admin->id]);
    $this->actingAs($this->customer)
        ->postJson("/api/v1/billing/payment-orders/{$first->id}/declare", ['payment_reference' => 'TX-UNIQUE'])
        ->assertOk();

    $this->actingAs($this->otherCustomer)
        ->postJson("/api/v1/billing/payment-orders/{$second->id}/declare", ['payment_reference' => 'tx-unique'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_reference');
    $this->actingAs($this->customer)
        ->postJson("/api/v1/billing/payment-orders/{$second->id}/declare", ['payment_reference' => 'AUTRE'])
        ->assertNotFound();
});

it('montre au client le motif de refus sans exposer les notes internes', function () {
    $order = ManualPaymentOrder::factory()->for($this->customer)->create([
        'created_by' => $this->admin->id,
        'status' => ManualPaymentOrder::STATUS_REJECTED,
        'rejection_reason' => 'La référence ne correspond à aucun encaissement.',
        'rejected_at' => now(),
        'internal_notes' => 'Vérification avec la caisse terminée.',
    ]);

    $this->actingAs($this->customer)->getJson('/api/v1/billing/payment-orders')
        ->assertJsonPath('data.0.id', $order->id)
        ->assertJsonPath('data.0.rejection_reason', 'La référence ne correspond à aucun encaissement.')
        ->assertJsonMissingPath('data.0.internal_notes');
});
