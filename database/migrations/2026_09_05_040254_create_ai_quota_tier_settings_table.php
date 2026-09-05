<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réglage de quota IA par palier — mibeko-dashboard#95.
     *
     * Une ligne par palier (`standard`/`user_pro`/`admin`) qui, quand elle
     * existe, remplace la limite de `config('ai.quotas.<tier>.*')` sans
     * redéploiement. Absence de ligne = repli sur `config/ai.php` (le défaut
     * ne disparaît jamais, il reste le filet). Ne porte QUE la limite, jamais
     * la portée (jour/mois) : la portée reste fixée par le rôle dans
     * `AiUserQuotaTier::resolve()` — c'est ce qui empêche cette table de
     * réactiver par la bande la bascule journalière du palier gratuit,
     * décidée le 04/09/2026 mais explicitement conditionnée (voir
     * `docs/decisions.md`) à un préalable qui n'est pas construit.
     */
    public function up(): void
    {
        Schema::create('ai_quota_tier_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tier', 20)->unique();
            $table->unsignedInteger('limit');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_quota_tier_settings');
    }
};
