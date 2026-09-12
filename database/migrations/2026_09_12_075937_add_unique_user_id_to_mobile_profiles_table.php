<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ferme une race condition sur `mobile_profiles` — mibeko-dashboard#135.
 *
 * `user_id` n'a jamais porté de contrainte UNIQUE. `ProfileController::update`
 * teste `$user->mobileProfile` puis choisit `update()` ou `create()` sans
 * transaction ni verrou : deux PATCH concurrents sur un compte sans profil
 * peuvent chacun lire `null` et créer chacun leur ligne. Le correctif applicatif
 * (upsert atomique sur `user_id`) a besoin d'une vraie contrainte DB pour que
 * Postgres arbitre lui-même le conflit.
 *
 * Cette migration ne fusionne ni ne supprime aucune ligne existante : si des
 * doublons sont déjà là, elle échoue bruyamment pour qu'un humain dédoublonne
 * délibérément (docs/infra/production.md §6) plutôt que de deviner laquelle
 * des deux lignes garder.
 */
return new class extends Migration
{
    public function up(): void
    {
        $doublons = DB::table('mobile_profiles')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        if ($doublons->isNotEmpty()) {
            throw new RuntimeException(
                'mobile_profiles contient des doublons user_id ('.$doublons->implode(', ').') — '.
                'dédoublonner manuellement avant de rejouer cette migration. Voir docs/infra/production.md §6.'
            );
        }

        Schema::table('mobile_profiles', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_profiles', function (Blueprint $table) {
            $table->dropUnique(['mobile_profiles_user_id_unique']);
        });
    }
};
