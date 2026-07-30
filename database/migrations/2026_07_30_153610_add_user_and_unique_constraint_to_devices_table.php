<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rattache un appareil à son propriétaire et rend `device_id` réellement unique.
     *
     * La table `devices` n'avait aucun index hors clé primaire, aucune contrainte
     * d'unicité et aucune colonne `user_id`. Deux conséquences bloquantes pour la
     * chaîne de veille légale :
     *  - le `updateOrCreate(['device_id' => …])` de l'enregistrement pouvait créer
     *    des doublons en course (deux requêtes simultanées du même téléphone) ;
     *  - rien ne disait À QUI appartient un appareil, donc rien ne permettait de
     *    respecter les préférences de notification de son propriétaire.
     *
     * `user_id` reste NULLABLE à dessein : l'app mobile enregistre son jeton push
     * avant toute connexion (mode invité), et ces appareils doivent continuer à
     * recevoir la veille générale.
     */
    public function up(): void
    {
        // La contrainte d'unicité échouerait sur une base contenant déjà des
        // doublons (dev comme production, alimentées par l'ancien `updateOrCreate`
        // non protégé). On conserve la ligne la plus récente de chaque
        // `device_id` — c'est elle qui porte le jeton FCM encore valide.
        $this->deduplicateDeviceIds();

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignUuid('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();

            $table->unique('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['device_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }

    /**
     * Supprime les doublons de `device_id` en gardant l'enregistrement le plus
     * récent (dernier enregistrement connu, à défaut la dernière mise à jour).
     *
     * Seule voie possible : une contrainte UNIQUE ne peut pas être posée sur une
     * colonne qui contient des doublons. Le nombre de lignes retirées est journalisé.
     *
     * Publique pour être exerçable par un test (cf. DeviceDeduplicationTest) :
     * la règle de conservation ne doit pas rester non vérifiée.
     */
    public function deduplicateDeviceIds(): void
    {
        $staleIds = DB::table(DB::raw('(
            SELECT id, ROW_NUMBER() OVER (
                PARTITION BY device_id
                ORDER BY COALESCE(last_registered_at, updated_at, created_at) DESC NULLS LAST, id DESC
            ) AS row_rank
            FROM devices
        ) AS ranked'))
            ->where('row_rank', '>', 1)
            ->pluck('id');

        if ($staleIds->isEmpty()) {
            return;
        }

        DB::table('devices')->whereIn('id', $staleIds)->delete();

        Log::info('Migration devices: '.$staleIds->count().' doublon(s) de device_id retiré(s) avant la pose de la contrainte unique.');
    }
};
