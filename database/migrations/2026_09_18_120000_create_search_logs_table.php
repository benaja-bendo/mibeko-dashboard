<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des recherches — mibeko-dashboard#111.
     *
     * En AJOUT SEUL : une ligne par recherche, sur les cinq points d'entrée
     * (library/search, library/suggest, search, articles/search,
     * legal-documents/search), succès comme recherche sans résultat. Pas de
     * `updated_at` : une ligne ne se corrige jamais après coup.
     *
     * `query` est plafonnée à 255 caractères — cf. l'incident `AiRouteName`
     * (varchar 40 débordé par un chemin non borné) : mieux vaut tronquer que
     * risquer une exception SQL sur le chemin de recherche public.
     */
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // nullOnDelete : le journal survit à la suppression du compte
            // (anonymisé explicitement par PrivacyController::destroy()).
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('query', 255);
            $table->unsignedInteger('results_count');
            $table->string('surface', 40);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['query', 'created_at']);
            $table->index(['results_count', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
