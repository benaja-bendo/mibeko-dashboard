<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Point d'écriture et de lecture unique du grand livre de crédits —
 * mibeko-dashboard#66.
 *
 * Le solde n'est jamais stocké : il se dérive par `SUM(amount)` sur
 * `credit_ledger_entries`. `consume()` est la seule opération qui a besoin
 * d'atomicité (elle seule peut être refusée si le solde ne suit pas) ; un
 * achat ou une correction ne violent aucune invariante et s'écrivent en
 * ajout simple.
 *
 * ⚠️ Composant construit par dérogation à « ventes manuelles d'abord »
 * (`docs/decisions.md`, 04/09/2026) : rien ici ne branche encore un parcours
 * d'achat ni la consommation réelle des requêtes IA — c'est la fondation,
 * pas l'intégration.
 */
class CreditLedger
{
    public function purchase(User $user, int $amount, ?string $reason = null, ?string $referenceId = null): CreditLedgerEntry
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Un achat de crédits doit être positif ({$amount} donné).");
        }

        return CreditLedgerEntry::create([
            'user_id' => $user->id,
            'type' => CreditLedgerEntry::TYPE_PURCHASE,
            'amount' => $amount,
            'reason' => $reason,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * Ajustement manuel, à la hausse ou à la baisse — jamais anonyme.
     */
    public function correction(User $user, int $amount, string $reason, User $createdBy, ?string $referenceId = null): CreditLedgerEntry
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('Une correction à zéro ne mouvemente rien.');
        }

        return CreditLedgerEntry::create([
            'user_id' => $user->id,
            'type' => CreditLedgerEntry::TYPE_CORRECTION,
            'amount' => $amount,
            'reason' => $reason,
            'reference_id' => $referenceId,
            'created_by' => $createdBy->id,
        ]);
    }

    /**
     * Consomme `$amount` crédits (positif) si le solde le permet, de façon
     * atomique sous deux requêtes simultanées : un verrou consultatif
     * Postgres, scopé à la transaction et à l'utilisateur, sérialise les
     * consommations concurrentes sans verrouiller de colonne de solde qui
     * n'existe pas. Il se libère seul au COMMIT/ROLLBACK.
     *
     * @throws InsufficientCreditsException si le solde est inférieur au montant demandé.
     */
    public function consume(User $user, int $amount, ?string $reason = null, ?string $referenceId = null): CreditLedgerEntry
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Une consommation de crédits doit être positive ({$amount} donné).");
        }

        return DB::transaction(function () use ($user, $amount, $reason, $referenceId) {
            $this->lock($user);

            $balance = $this->balance($user);

            if ($balance < $amount) {
                throw new InsufficientCreditsException($user, $balance, $amount);
            }

            return CreditLedgerEntry::create([
                'user_id' => $user->id,
                'type' => CreditLedgerEntry::TYPE_CONSUMPTION,
                'amount' => -$amount,
                'reason' => $reason,
                'reference_id' => $referenceId,
            ]);
        });
    }

    public function balance(User $user): int
    {
        return (int) CreditLedgerEntry::where('user_id', $user->id)->sum('amount');
    }

    /**
     * `hashtextextended` (Postgres ≥ 9.5) réduit l'UUID utilisateur à un
     * bigint stable pour `pg_advisory_xact_lock` — qui n'accepte pas de
     * verrouiller directement une clé texte.
     */
    private function lock(User $user): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$user->id]);
    }
}
