<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Détache l'historique de paiement du compte au lieu de l'effacer avec lui
     * (décision du 24/09/2026, `docs/decisions.md`).
     *
     * `plan_grants.user_id` et `manual_payment_orders.user_id` étaient en
     * `ON DELETE CASCADE` : la purge définitive d'un compte supprimé
     * (`mibeko:purger-comptes-supprimes`) aurait emporté les preuves de
     * paiement, qu'il faut garder dix ans pour les obligations comptables.
     * En `SET NULL`, montant, date, canal et référence restent, sans lien vers
     * une personne. Le code lit déjà ces relations sans supposer de titulaire
     * (`$grant->user?->…`) : un compte supprimé y renvoyait déjà `null`.
     *
     * @var list<string>
     */
    private array $tables = ['plan_grants', 'manual_payment_orders'];

    public function up(): void
    {
        foreach ($this->tables as $nom) {
            Schema::table($nom, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->uuid('user_id')->nullable()->change();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $nom) {
            // Revenir au NOT NULL exigerait d'effacer les paiements détachés,
            // c'est-à-dire exactement ce que cette migration protège : on
            // refuse plutôt que de choisir à la place de l'humain.
            $detaches = DB::table($nom)->whereNull('user_id')->count();

            if ($detaches > 0) {
                throw new RuntimeException("{$nom} : {$detaches} paiement(s) détaché(s) d'un compte purgé — retour arrière refusé, il les effacerait.");
            }
        }

        foreach ($this->tables as $nom) {
            Schema::table($nom, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->uuid('user_id')->nullable(false)->change();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
