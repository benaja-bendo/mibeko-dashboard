<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crée la table `user_settings` (préférences applicatives + consentements RGPD).
     *
     * Une ligne par utilisateur. Les préférences de notification sont stockées en
     * JSON (matrice canal × type × fréquence) pour rester évolutives sans migration.
     * Les consentements gardent une date d'horodatage : la traçabilité fine
     * (qui/quand/ancienne valeur) est assurée par owen-it/laravel-auditing.
     */
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            // Clé UUID pour rester compatible avec la table `audits` (auditable_id UUID).
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();

            // Préférences d'affichage / localisation
            $table->string('locale', 8)->default('fr');
            $table->string('timezone', 64)->default('Africa/Kinshasa');
            $table->string('date_format', 20)->default('d/m/Y');

            // Préférences de notification : { "<type>": { "email": bool, "push": bool, "in_app": bool }, "_frequency": "instant|daily|weekly" }
            $table->json('notification_preferences')->nullable();

            // Consentements RGPD (l'historique détaillé est dans la table `audits`)
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
            $table->boolean('analytics_consent')->default(false);
            $table->timestamp('analytics_consent_at')->nullable();

            // Infos légales de facturation : { company, rccm, tax_id, address } (contexte RDC)
            $table->json('billing_info')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Supprime la table `user_settings`.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
