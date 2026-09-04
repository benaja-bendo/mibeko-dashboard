<?php

namespace App\Exceptions;

use App\Models\User;
use Exception;

/**
 * Levée par `CreditLedger::consume()` quand le solde dérivé du grand livre
 * (mibeko-dashboard#66) est insuffisant pour couvrir la consommation demandée.
 */
class InsufficientCreditsException extends Exception
{
    public function __construct(
        public readonly User $user,
        public readonly int $balance,
        public readonly int $requested,
    ) {
        parent::__construct("Solde de crédits insuffisant pour l'utilisateur {$user->id} : {$balance} disponible(s), {$requested} demandé(s).");
    }
}
