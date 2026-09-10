<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grand livre des mouvements FCFA d'un octroi Pro — mibeko-dashboard#122.
 *
 * Même doctrine que `credit_ledger_entries` (#66) : en AJOUT SEUL, aucune
 * colonne de solde nulle part — brut, remboursements et net se DÉRIVENT par
 * `SUM(amount_fcfa)` (voir `PlanGrantLedger`). `plan_grants.amount_fcfa`
 * garde son sens actuel (montant SAISI à la vente, jamais réécrit) ; ce grand
 * livre devient la seule source pour le montant réellement ENCAISSÉ et les
 * remboursements — une correction est toujours une nouvelle ligne, jamais
 * une réécriture d'une ligne existante ni de `plan_grants.amount_fcfa`.
 *
 * `occurred_at` est distinct de `created_at` : c'est la date réelle de
 * l'encaissement/remboursement, pas celle de la saisie — nécessaire pour
 * qu'une période comptable déjà close ne soit jamais réécrite par une
 * correction tardive (elle apparaît dans la période où elle a réellement
 * lieu, pas dans celle qu'elle corrige).
 */
return new class extends Migration
{
    private const TYPES = ['collected', 'refund', 'correction'];

    public function up(): void
    {
        Schema::create('plan_grant_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_grant_id')->constrained()->cascadeOnDelete();
            // Nullable : une correction peut ne pas remonter à une commande
            // précise (erreur de saisie ancienne, mouvement manuel).
            $table->foreignUuid('manual_payment_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->integer('amount_fcfa');
            $table->timestamp('occurred_at');
            // Idempotence du rejeu, même convention que credit_ledger_entries :
            // pas d'unicité en base (les sources varient), le contrôleur
            // vérifie « même référence, mêmes données » avant d'écrire.
            $table->string('reference_id', 64)->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['plan_grant_id', 'type']);
            $table->index('reference_id');
        });

        DB::statement(
            'ALTER TABLE plan_grant_movements ADD CONSTRAINT plan_grant_movements_type_check '.
            'CHECK (type IN ('.collect(self::TYPES)->map(fn (string $t) => "'{$t}'")->implode(', ').'))'
        );

        // Un mouvement à zéro ne mouvemente rien : ne peut être qu'un bug.
        DB::statement(
            'ALTER TABLE plan_grant_movements ADD CONSTRAINT plan_grant_movements_amount_not_zero_check '.
            'CHECK (amount_fcfa <> 0)'
        );

        // Signe imposé par type : un encaissement ne peut qu'ajouter, un
        // remboursement ne peut que retrancher — une correction reste libre
        // dans les deux sens (erreur de saisie constatée après coup).
        DB::statement(
            'ALTER TABLE plan_grant_movements ADD CONSTRAINT plan_grant_movements_sign_check '.
            "CHECK ((type = 'collected' AND amount_fcfa > 0) OR (type = 'refund' AND amount_fcfa < 0) OR type = 'correction')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_grant_movements');
    }
};
