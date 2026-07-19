<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structure d'un signal de confiance HONNÊTE : « vérifié par un juriste le … ».
 *
 * On ajoute UNIQUEMENT les colonnes (nullable). Rien n'est peuplé : tant qu'un
 * juriste n'a pas réellement relu une version, `reviewed_at` reste null et
 * l'interface n'affiche AUCUNE mention de vérification. Peupler ces colonnes est
 * un acte éditorial (workflow à construire), jamais une valeur inventée.
 *
 * Portée « version » (et non « article ») : une relecture juridique porte sur un
 * CONTENU précis, qui vit dans `article_versions` (comme `is_verified` et
 * `validity_period`). Une nouvelle version repart donc logiquement non relue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_versions', function (Blueprint $table) {
            // Juriste ayant relu ce contenu. FK nullable : la version peut ne
            // jamais avoir été relue, et la suppression du compte ne doit pas
            // effacer la version (on détache simplement le relecteur).
            $table->foreignUuid('reviewed_by')
                ->nullable()
                ->after('is_verified')
                ->constrained('users')
                ->nullOnDelete();

            // Horodatage de la relecture. La PRÉSENCE d'une date (non null) est le
            // seul signal légitime pour afficher « vérifié par un juriste le … ».
            $table->timestamp('reviewed_at')
                ->nullable()
                ->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('article_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
        });
    }
};
