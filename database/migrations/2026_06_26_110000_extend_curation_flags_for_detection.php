<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Étend `curation_flags` pour accueillir la détection d'anomalies multi-couches
     * (heuristique numérotation existante + structurelle + sémantique/LLM) et un
     * meilleur ciblage / tri pour la validation humaine :
     *  - `source`     : origine du signalement (purge sélective des couches machine
     *                   sans jamais toucher les signalements humains) ;
     *  - `node_id`    : viser une DIVISION (et plus seulement un article) ;
     *  - `severity`   : seul `blocking` empêche la publication (warning/info informent) ;
     *  - `suggestion` : proposition de correction (ex. scission) — jamais appliquée seule ;
     *  - `anchor`     : ancrage précis (page, offsets, tree_path) pour surligner ;
     *  - `confidence` : confiance du détecteur (tri, seuil d'auto-masquage) ;
     *  - `run_id`     : rattache un lot de détection (idempotence des re-runs).
     */
    public function up(): void
    {
        Schema::table('curation_flags', function (Blueprint $table) {
            // Rétro-compat : les lignes existantes (heuristique Python, signalements
            // utilisateurs) deviennent 'human'/'blocking' → comportement inchangé
            // (le détecteur ne purge JAMAIS les flags 'human').
            $table->string('source', 20)->default('human')->after('article_id');
            $table->string('severity', 20)->default('blocking')->after('type_probleme');
            $table->foreignUuid('node_id')->nullable()->after('article_id')
                ->constrained('structure_nodes')->cascadeOnDelete();
            $table->jsonb('suggestion')->nullable()->after('description');
            $table->jsonb('anchor')->nullable()->after('suggestion');
            $table->decimal('confidence', 5, 4)->nullable()->after('anchor');
            $table->uuid('run_id')->nullable()->after('confidence');
        });

        // Tri/filtrage côté triage et détecteur (idempotence par source+résolution).
        Schema::table('curation_flags', function (Blueprint $table) {
            $table->index(['document_id', 'resolved', 'source'], 'idx_curation_flags_doc_resolved_source');
        });
    }

    public function down(): void
    {
        Schema::table('curation_flags', function (Blueprint $table) {
            $table->dropIndex('idx_curation_flags_doc_resolved_source');
            $table->dropConstrainedForeignId('node_id');
            $table->dropColumn(['source', 'severity', 'suggestion', 'anchor', 'confidence', 'run_id']);
        });
    }
};
