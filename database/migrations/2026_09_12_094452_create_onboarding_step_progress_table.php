<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progression par étape, identifiée par une clé STABLE (`step_key`) plutôt
 * que par position — mibeko-dashboard#136. `viewed_at`/`skipped_at`/
 * `completed_at` sont trois faits INDÉPENDANTS (jamais un statut unique) :
 * - `completed_at` est IMMUABLE une fois posé (jamais réécrit, même par un
 *   replay de l'inscription parente) : garantit "préserve la première
 *   réussite".
 * - `skipped_at` ne pose jamais `completed_at` : "passer" n'équivaut jamais
 *   à une première réussite. Une étape déjà passée peut plus tard être
 *   réellement répondue (`completed_at` se pose alors, `skipped_at` reste).
 *
 * `value` porte la réponse brute SAUF quand l'étape est liée à un binding
 * sensible (`profile.phone`) : dans ce cas la colonne reste NULL par
 * construction (OnboardingStepWriter le force, pas une convention laissée
 * à la discipline du développeur) — la valeur vit uniquement dans
 * `mobile_profiles`, jamais dupliquée ici.
 *
 * `client_updated_at` (epoch ms fourni par le CLIENT) arbitre les écritures
 * concurrentes/hors-ligne — même doctrine que
 * `DossierController::mergeDossier` (`dossiers.client_updated_at`), jamais
 * l'horloge serveur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_step_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrollment_id')->constrained('onboarding_enrollments')->cascadeOnDelete();
            $table->string('step_key', 100);
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('skipped_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('value')->nullable();
            $table->string('last_client_mutation_id')->nullable();
            $table->bigInteger('client_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'step_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_step_progress');
    }
};
