<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grand livre de crédits — mibeko-dashboard#66.
 *
 * En AJOUT SEUL, comme `ai_usage_logs` : achat, consommation, correction.
 * Aucune colonne de solde nulle part — le solde se DÉRIVE par `SUM(amount)`
 * (voir `CreditLedger::balance()`), pour qu'une contestation client se tranche
 * en relisant les lignes plutôt qu'en faisant confiance à un compteur qui a pu
 * dériver. `amount` est signé : positif pour un crédit acquis, négatif pour
 * un crédit consommé ou une correction débitrice.
 *
 * ⚠️ Dérogation actée le 04/09/2026 (`docs/decisions.md`) à la doctrine
 * « ventes manuelles d'abord » : ce grand livre est construit par
 * anticipation, avant toute vente manuelle validée. Il reste un composant
 * interne tant qu'aucun parcours d'achat n'est branché dessus.
 */
return new class extends Migration
{
    private const TYPES = ['purchase', 'consumption', 'correction'];

    public function up(): void
    {
        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // nullOnDelete plutôt que cascade, même raison que ai_usage_logs :
            // un grand livre financier survit à la suppression du compte.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->integer('amount');
            // Explication courte du mouvement (obligatoire en pratique pour
            // une 'correction', laissée nullable en base pour ne pas dupliquer
            // une contrainte que l'application porte déjà).
            $table->string('reason', 255)->nullable();
            // Référence libre vers l'objet à l'origine du mouvement — typiquement
            // `ai_usage_logs.id` pour une consommation, c'est ce qui permet la
            // réconciliation entre les deux journaux. Pas de FK déclarée : les
            // sources varient selon le type, même convention que
            // ai_usage_logs.conversation_id.
            $table->string('reference_id', 36)->nullable();
            // Qui a posé l'écriture — surtout utile pour une 'correction'
            // manuelle ; nul pour un mouvement système (achat automatisé,
            // consommation déclenchée par l'assistant IA).
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('reference_id');
        });

        DB::statement(
            'ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_type_check '.
            'CHECK (type IN ('.collect(self::TYPES)->map(fn (string $t) => "'{$t}'")->implode(', ').'))'
        );

        // Une ligne à zéro ne mouvemente rien : elle ne peut être qu'un bug.
        DB::statement(
            'ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_entries_amount_not_zero_check '.
            'CHECK (amount <> 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
    }
};
