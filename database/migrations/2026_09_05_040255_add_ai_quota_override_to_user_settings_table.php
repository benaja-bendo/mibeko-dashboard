<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Override de quota IA par utilisateur — mibeko-dashboard#95.
     *
     * Pensé pour une vente manuelle (§11.3) : le fondateur pose lui-même un
     * chiffre depuis l'admin après un arrangement hors parcours self-service.
     * `limit` prime sur le réglage de palier ET sur `config/ai.php` quand il
     * est posé ; ne porte que la limite, jamais la portée (même raison que
     * `ai_quota_tier_settings` : la portée reste fixée par le rôle).
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->unsignedInteger('ai_quota_override_limit')->nullable()->after('billing_info');
            $table->string('ai_quota_override_note')->nullable()->after('ai_quota_override_limit');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['ai_quota_override_limit', 'ai_quota_override_note']);
        });
    }
};
