<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inscription d'un compte à UNE VERSION PRÉCISE d'un parcours — épinglée à
 * `journey_id` pour toujours (mibeko-dashboard#136). Une v2 publiée après
 * coup ne déplace jamais une inscription déjà créée sur v1.
 *
 * `completed_at`/`started_at`/`postponed_at` sont des marqueurs HISTORIQUES
 * ("la première fois que X est arrivé"), pas des miroirs de `status` : un
 * replay repasse `status` à `in_progress` SANS effacer `completed_at`
 * (préserve la date de première réussite — même doctrine que
 * `onboarding_step_progress.completed_at`, immuable). Aucune contrainte
 * CHECK ne lie donc `status` à la présence de ces timestamps.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const STATUSES = ['not_started', 'in_progress', 'postponed', 'completed'];

    public function up(): void
    {
        Schema::create('onboarding_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('journey_id')->constrained('onboarding_journeys')->restrictOnDelete();
            // Dénormalisé pour permettre WHERE user_id=? AND journey_key=? sans
            // jointure — c'est aussi la clé de l'UNIQUE ci-dessous (un compte
            // n'a qu'une seule inscription par famille de parcours, quelle
            // que soit la version précise pointée par journey_id).
            $table->string('journey_key', 60);
            $table->string('status', 20)->default('not_started');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('last_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('postponed_at')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->unsignedInteger('replay_count')->default(0);
            // Idempotence des actions de l'inscription (postpone/replay) —
            // protège replay_count d'un double-incrément sur retry réseau.
            $table->string('last_client_mutation_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'journey_key']);
        });

        DB::statement(
            'ALTER TABLE onboarding_enrollments ADD CONSTRAINT onboarding_enrollments_status_check '.
            "CHECK (status IN ('".implode("', '", self::STATUSES)."'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_enrollments');
    }
};
