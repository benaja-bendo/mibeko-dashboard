<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mémorise la version de l'app installée sur l'appareil.
     *
     * La veille légale pousse désormais des messages FCM « data-only » : c'est
     * la seule forme qui garantit le deep link (un bloc `notification` fait
     * afficher l'alerte par Android sans passer par `onMessageReceived`, où
     * l'app lit le `data`). Mais l'app publiée sur les stores (v1.0/v1.1) ne
     * sait pas AFFICHER un message data-only — le repli sur `data["title"]` /
     * `data["message"]` n'arrive qu'en v1.2. Sans cette colonne, activer la
     * veille enverrait des alertes invisibles à tout le parc installé, et le
     * marqueur d'idempotence (`legal_documents.watch_notified_at`) les
     * consommerait définitivement.
     *
     * Nullable, et les lignes existantes le restent : version inconnue = format
     * hérité (bloc `notification` + `data`), qui s'affiche sur toutes les
     * générations d'app. Le repli par défaut est donc le repli sûr.
     *
     * Pas d'index : la répartition se fait en PHP sur des tranches d'appareils
     * déjà chargées (cf. `SendLegalWatchNotifications::dispatchPushes`), aucune
     * requête ne filtre ni ne trie sur cette colonne. Un index ne coûterait que
     * des écritures, à chaque ré-enregistrement d'appareil.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('app_version', 20)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('app_version');
        });
    }
};
