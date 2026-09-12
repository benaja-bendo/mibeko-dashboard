<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégat DURABLE d'activation, séparé du détail nominatif — mibeko-dashboard#137.
 *
 * Aucune colonne `user_id` : cette table n'est PAS une donnée personnelle,
 * elle n'entre ni dans l'export RGPD (`PrivacyController::export()`) ni
 * dans la purge du détail (`mibeko:purge-product-events`) — c'est elle qui
 * SURVIT à cette purge. Calculée par `mibeko:purge-product-events`, jamais
 * par `mibeko:kpis` (qui reste un rapport 100% lecture, sans écriture).
 *
 * Grain volontairement plus grossier qu'une ventilation par objectif/version
 * (une ligne par semaine de cohorte, pas par `usage_context`) : la
 * ventilation fine reste possible tant que le détail vit (`mibeko:kpis
 * activation()`, fenêtre glissante de 12 semaines, bien sous la rétention).
 * Cette table sert un usage à plus long horizon (revue au-delà de la
 * rétention du détail) — sacrifier la finesse pour un grain qui ne dépend
 * d'aucune colonne nullable dans une contrainte unique (Postgres traite deux
 * NULL comme distincts, ce qui casserait un upsert idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_activation_cohort_stats', function (Blueprint $table) {
            $table->id();
            $table->date('cohort_week')->unique();
            $table->unsignedInteger('cohort_size');
            $table->unsignedInteger('reached_search_useful');
            $table->unsignedInteger('reached_success_reply');
            $table->unsignedInteger('reached_activation_candidate');
            $table->decimal('median_days_to_activation', 6, 2)->nullable();
            $table->unsignedInteger('d7_eligible');
            $table->unsignedInteger('d7_returned');
            $table->timestamp('computed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_activation_cohort_stats');
    }
};
