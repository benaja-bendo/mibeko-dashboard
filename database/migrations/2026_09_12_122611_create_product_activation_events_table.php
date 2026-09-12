<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Détail nominatif des 2 jalons d'activation qui n'ont AUCUNE autre source
 * de vérité serveur — mibeko-dashboard#137. « Parcours commencé », « étape
 * vue/répondue/passée/reportée », « guide terminé/rejoué » (#136) et
 * « réponse réussie » (`ai_usage_logs`, #61) ont déjà leur horodatage
 * serveur ailleurs : aucune ligne n'est dupliquée ici pour eux.
 *
 * En AJOUT SEUL, même doctrine que `ai_usage_logs` (#61) : aucune colonne
 * texte libre, pas d'`updated_at`, `created_at` posé par Postgres
 * (`useCurrent()`) — jamais l'horloge PHP, pour que le dédoublonnage
 * cross-surface par `MIN(created_at)` ne puisse pas dériver entre serveurs
 * applicatifs. `reference_id` est un identifiant opaque (article ou
 * ai_usage_log), jamais un texte de requête ou de réponse.
 *
 * Rétention bornée par `mibeko:purge-product-events` (config
 * `product_activation.retention_days`) ; l'agrégat durable qui en survit
 * vit dans `product_activation_cohort_stats` (migration suivante), sans
 * `user_id`.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const EVENT_TYPES = ['search_useful', 'source_opened_after_answer'];

    /**
     * @var list<string>
     */
    private const SURFACES = ['web', 'mobile'];

    public function up(): void
    {
        Schema::create('product_activation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 30);
            $table->string('surface', 10);
            // Snapshots serveur au moment de l'événement — jamais fournis par
            // le client (cf. ProductEventStoreRequest) : "l'objectif choisi"
            // (#135) et la version de parcours d'onboarding actif (#136).
            $table->string('usage_context', 20)->nullable();
            $table->unsignedInteger('onboarding_journey_version')->nullable();
            $table->string('reference_type', 20);
            $table->uuid('reference_id');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('client_event_id', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'event_type', 'client_event_id']);
            $table->index(['event_type', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE product_activation_events ADD CONSTRAINT product_activation_events_event_type_check '.
            "CHECK (event_type IN ('".implode("', '", self::EVENT_TYPES)."'))"
        );
        DB::statement(
            'ALTER TABLE product_activation_events ADD CONSTRAINT product_activation_events_surface_check '.
            "CHECK (surface IN ('".implode("', '", self::SURFACES)."'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_activation_events');
    }
};
