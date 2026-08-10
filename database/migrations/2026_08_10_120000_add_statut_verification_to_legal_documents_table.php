<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape 0 du protocole de validation, mesurée le 10/08/2026 : les 795 documents
 * publiés portent tous `statut = 'vigueur'` — non parce que quelqu'un l'a
 * vérifié, mais parce que la colonne a `'vigueur'` pour valeur par défaut. Le
 * corpus publie ainsi comme applicables, en même temps, la Constitution de la
 * République Populaire du Congo de 1973, l'Acte fondamental de 1997 et la
 * Constitution de 2015. Aucun détecteur de qualité ne peut le voir : un texte
 * abrogé est parfaitement fidèle à sa source.
 *
 * Ces deux colonnes ne changent pas la sémantique de `statut` — elles disent
 * si quelqu'un l'a établi, et quand. `NULL` signifie « jamais vérifié », ce qui
 * est aujourd'hui le cas de tout le corpus. Choix délibérément ADDITIF, sur le
 * modèle de `date_entree_vigueur_inconnue` (migration du 02/08) : ajouter une
 * valeur `non_verifie` à l'énumération aurait cassé les clients déjà installés
 * qui aiguillent sur `vigueur|abroge|projet`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->timestamp('statut_verifie_le')->nullable()->after('statut');
            $table->foreignUuid('statut_verifie_par')->nullable()->after('statut_verifie_le')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('statut_verifie_par');
            $table->dropColumn('statut_verifie_le');
        });
    }
};
