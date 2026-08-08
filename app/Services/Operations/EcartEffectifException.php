<?php

namespace App\Services\Operations;

use RuntimeException;

/**
 * L'effectif réellement touché par un lot dévie de son `expected_rows`.
 *
 * Levée DANS la transaction du lot pour l'annuler : rien de partiel n'est
 * conservé, le lot reste dans pending/ et la file s'arrête net — un écart
 * d'effectif se traite comme un incident, jamais comme un avertissement
 * (docs/infra/production.md § 6 bis).
 */
class EcartEffectifException extends RuntimeException
{
    public function __construct(public readonly int $touchees, public readonly int $annoncees)
    {
        parent::__construct("Effectif touché ({$touchees}) ≠ annoncé ({$annoncees}).");
    }
}
