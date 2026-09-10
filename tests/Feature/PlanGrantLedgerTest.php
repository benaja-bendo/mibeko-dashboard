<?php

use App\Models\ManualPaymentOrder;
use App\Models\PlanGrant;
use App\Models\PlanGrantMovement;
use App\Models\User;
use App\Services\PlanGrantLedger;

/**
 * mibeko-dashboard#122 : grand livre FCFA en ajout seul, net dérivé — même
 * doctrine que CreditLedger (#66), jamais de colonne de solde stockée.
 */
it('dérive brut, remboursements et net en sommant les mouvements, sans jamais les stocker', function () {
    $user = User::factory()->create();
    $grant = PlanGrant::factory()->for($user)->create(['amount_fcfa' => 15000]);
    $ledger = new PlanGrantLedger;

    $ledger->collect($grant, 15000, null, now(), $user);
    $ledger->refund($grant, -5000, 'Remboursement partiel', 'RB-1', $user);
    $ledger->correction($grant, 500, 'Frais bancaire recrédité', 'CORR-1', $user);

    expect($grant->movements()->sum('amount_fcfa'))->toBe(10500)
        ->and(PlanGrantMovement::where('plan_grant_id', $grant->id)->count())->toBe(3);
});

it('valide les montants aux limites du service : encaissement positif, remboursement négatif, correction non nulle', function () {
    $user = User::factory()->create();
    $grant = PlanGrant::factory()->for($user)->create();
    $ledger = new PlanGrantLedger;

    expect(fn () => $ledger->collect($grant, 0, null, now(), $user))->toThrow(InvalidArgumentException::class);
    expect(fn () => $ledger->refund($grant, 100, 'x', 'RB-2', $user))->toThrow(InvalidArgumentException::class);
    expect(fn () => $ledger->correction($grant, 0, 'x', 'CORR-2', $user))->toThrow(InvalidArgumentException::class);
});

it('rattache un encaissement à sa commande d\'origine et l\'horodate à la date réelle, pas à celle de la saisie', function () {
    $user = User::factory()->create();
    $grant = PlanGrant::factory()->for($user)->create();
    $order = ManualPaymentOrder::factory()->for($user)->create();
    $ledger = new PlanGrantLedger;

    $movement = $ledger->collect($grant, 15000, $order, now()->subDays(2), $user);

    expect($movement->manual_payment_order_id)->toBe($order->id)
        ->and($movement->occurred_at->isSameDay(now()->subDays(2)))->toBeTrue()
        ->and($movement->created_at->isSameDay(now()))->toBeTrue();
});
