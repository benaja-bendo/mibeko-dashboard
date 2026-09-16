<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Abonnement d'un utilisateur à un texte (`LegalDocument`) ou à un thème
     * (`Tag`) — mibeko-dashboard#125. Table distincte du réglage global
     * `notification_preferences` (matrice type × canal sur `user_settings`) :
     * celui-ci gouverne la diffusion générale, celle-ci cible un texte ou un
     * thème précis. Les deux coexistent, ils ne se remplacent pas.
     */
    public function up(): void
    {
        Schema::create('legal_watch_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('watchable');
            $table->timestamps();

            // Un utilisateur ne peut suivre deux fois le même texte/thème.
            $table->unique(['user_id', 'watchable_type', 'watchable_id'], 'legal_watch_subscriptions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_watch_subscriptions');
    }
};
