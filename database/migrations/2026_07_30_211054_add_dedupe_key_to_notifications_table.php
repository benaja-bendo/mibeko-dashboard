<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clé d'unicité de DIFFUSION d'une notification in-app.
     *
     * `legal_documents.watch_notified_at` rend la RÉSERVATION idempotente (un
     * texte n'est annoncé qu'une fois), mais pas la diffusion : le job
     * `SendLegalWatchNotifications` est rejoué par la file après un échec
     * partiel (`tries = 3`) et réinsérait alors les lignes `notifications` déjà
     * écrites pour les utilisateurs déjà traités.
     *
     * `dedupe_key` porte l'identité métier de l'alerte pour un destinataire
     * (« ce texte », « cette synthèse ») ; l'unicité `(user_id, dedupe_key)`
     * transforme le rejeu en `ON CONFLICT DO NOTHING`. Postgres considérant
     * chaque NULL comme distinct, les notifications qui ne relèvent pas de la
     * veille (`dedupe_key` nul) ne sont pas contraintes : aucun index partiel
     * n'est nécessaire.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->after('type');

            $table->unique(['user_id', 'dedupe_key'], 'notifications_user_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_user_dedupe_unique');
            $table->dropColumn('dedupe_key');
        });
    }
};
