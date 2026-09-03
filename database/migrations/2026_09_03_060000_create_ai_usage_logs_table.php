<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal d'usage IA — mibeko-dashboard#61.
     *
     * En AJOUT SEUL : une ligne par appel aux trois routes IA
     * (`assistant/chat`, `library/explain`, `library/synthesis`), succès comme
     * échec. Pas de `updated_at` : une ligne ne se corrige jamais après coup,
     * elle se lit telle qu'écrite.
     *
     * Le compteur de quota (`RateLimiter`, en cache) n'est pas remplacé : ce
     * journal l'observe, il ne le pilote pas.
     */
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // nullOnDelete plutôt que cascade : un journal de coût survit à la
            // suppression du compte qui l'a généré.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('route', 40);
            // 'success' | 'cached' (servi sans appel LLM) | 'no_content' (erreur
            // de validation avant tout appel) | 'rate_limited' (429) | 'error'.
            $table->string('status', 20);
            $table->string('provider', 40)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('tokens_input')->default(0);
            $table->unsignedInteger('tokens_output')->default(0);
            // FCFA, 4 décimales : le coût d'une question isolée est souvent
            // inférieur à 1 FCFA, arrondir à l'entier écraserait le signal.
            $table->decimal('cost_estimated_fcfa', 10, 4)->nullable();
            // Table du package laravel/ai, sans FK déclarée côté package (même
            // convention que agent_message_feedback.message_id) : simple index.
            $table->string('conversation_id', 36)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['route', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
