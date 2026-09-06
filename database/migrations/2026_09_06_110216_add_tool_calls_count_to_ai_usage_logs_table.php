<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visibilité par étape de l'Assistant — mibeko-dashboard#103.
     *
     * Le SDK (`laravel/ai`) n'expose pas le détail des jetons par appel
     * modèle d'un agent en plusieurs étapes (chaque recherche supplémentaire
     * est un appel distinct qui réexpédie toute la conversation depuis le
     * début) : seul le total agrégé du tour est disponible. Ce compte du
     * nombre d'appels à `SearchLegalDatabase` est le proxy retenu — corrélé
     * au coût, sans intercepter les appels HTTP du fournisseur un par un.
     *
     * `NULL` pour les routes ou les statuts où la notion n'a pas de sens
     * (réponse en cache, refus de quota, bibliothèque à la demande — qui
     * n'utilise aucun outil) : jamais 0 par défaut, qui affirmerait à tort
     * qu'un agent a tourné sans chercher.
     */
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('tool_calls_count')->nullable()->after('cost_estimated_fcfa');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn('tool_calls_count');
        });
    }
};
