<?php

namespace App\Services;

use App\Models\ManualPaymentOrder;
use App\Models\PlanGrant;
use App\Models\PlanGrantMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Point d'écriture du grand livre FCFA d'un octroi Pro — mibeko-dashboard#122.
 *
 * Le brut, les remboursements et le net ne sont jamais stockés : ils se
 * dérivent par `SUM(amount_fcfa)` sur `plan_grant_movements` (voir
 * `Admin\BillingController::summary()`). Une correction erronée ne s'efface
 * jamais, elle se compense par un nouveau mouvement — c'est au lecteur
 * (rapport de période) de faire la somme, jamais à l'écriture de réparer une
 * ligne passée.
 *
 * Idempotence du rejeu (référence + contrôleur, même discipline que
 * `CreditLedger`) : cette classe n'est pas responsable de refuser un doublon,
 * c'est `Admin\BillingController::storeMovement()` qui vérifie « même
 * référence, mêmes données » avant d'appeler `refund()`/`correction()`.
 */
class PlanGrantLedger
{
    /**
     * Encaissement réellement reçu, distinct du montant saisi à la vente
     * (`plan_grant->amount_fcfa`, jamais réécrit). Appelé une seule fois,
     * à l'activation d'une commande — cet appel hérite donc de l'idempotence
     * déjà garantie par `ManualPaymentOrderController::activate()` (statut
     * de la commande), pas besoin d'une seconde vérification ici.
     */
    public function collect(PlanGrant $grant, int $amountFcfa, ?ManualPaymentOrder $order, ?CarbonInterface $occurredAt, ?User $createdBy): PlanGrantMovement
    {
        if ($amountFcfa <= 0) {
            throw new InvalidArgumentException("Un encaissement doit être positif ({$amountFcfa} donné).");
        }

        return PlanGrantMovement::create([
            'plan_grant_id' => $grant->id,
            'manual_payment_order_id' => $order?->id,
            'type' => PlanGrantMovement::TYPE_COLLECTED,
            'amount_fcfa' => $amountFcfa,
            'occurred_at' => $occurredAt ?? now(),
            'reference_id' => $order ? 'order:'.$order->id : null,
            'created_by' => $createdBy?->id,
        ]);
    }

    /**
     * Remboursement — toujours négatif, jamais anonyme. Ne touche ni
     * `plan_grants.amount_fcfa` ni l'octroi lui-même : couper l'accès est un
     * geste séparé et explicite (`UserController::revokeProPlan()` /
     * `PlanGrant::revoke()`), jamais un effet de bord automatique d'un
     * remboursement.
     */
    public function refund(PlanGrant $grant, int $amountFcfa, string $reason, string $referenceId, User $createdBy): PlanGrantMovement
    {
        if ($amountFcfa >= 0) {
            throw new InvalidArgumentException("Un remboursement doit être négatif ({$amountFcfa} donné).");
        }

        return PlanGrantMovement::create([
            'plan_grant_id' => $grant->id,
            'type' => PlanGrantMovement::TYPE_REFUND,
            'amount_fcfa' => $amountFcfa,
            'occurred_at' => now(),
            'reference_id' => $referenceId,
            'reason' => $reason,
            'created_by' => $createdBy->id,
        ]);
    }

    /**
     * Ajustement manuel, à la hausse ou à la baisse — pour réparer une
     * erreur de saisie constatée après coup, sans qu'un remboursement réel
     * ait eu lieu. Jamais anonyme.
     */
    public function correction(PlanGrant $grant, int $amountFcfa, string $reason, string $referenceId, User $createdBy): PlanGrantMovement
    {
        if ($amountFcfa === 0) {
            throw new InvalidArgumentException('Une correction à zéro ne mouvemente rien.');
        }

        return PlanGrantMovement::create([
            'plan_grant_id' => $grant->id,
            'type' => PlanGrantMovement::TYPE_CORRECTION,
            'amount_fcfa' => $amountFcfa,
            'occurred_at' => now(),
            'reference_id' => $referenceId,
            'reason' => $reason,
            'created_by' => $createdBy->id,
        ]);
    }
}
