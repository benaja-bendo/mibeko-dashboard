<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des passages du jeu de détecteurs de contenu (mibeko-dashboard#141,
 * § 3.5 du plan « boîte de réception »).
 *
 * Table dédiée plutôt que `legal_documents.metadata.controle` (option écartée
 * lors de la revue technique du 14/09) : `metadata` fait partie de
 * `LegalDocument::VALIDATION_INVALIDATING_FIELDS`, y écrire à chaque passage
 * planifié aurait repassé silencieusement en `review` tout document déjà
 * `validated`, sans qu'aucun contenu n'ait changé.
 *
 * Append-only (jamais d'UPDATE) : la mesure de conformité du registre #23
 * porte sur le DERNIER run par document et par version du jeu, jamais sur un
 * champ unique écrasé — un historique permet de voir qu'un document a été
 * contrôlé plusieurs fois et de dater chaque passage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_controle_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('legal_documents')->cascadeOnDelete();
            $table->string('version_jeu', 10);
            $table->timestamp('date')->useCurrent();
            $table->jsonb('resultats')->default('{}');
            $table->string('resultat', 20);

            // Requête de conformité (§ 3.5) : dernier run par document pour une
            // version du jeu donnée — jamais un scan complet de la table.
            $table->index(['document_id', 'version_jeu', 'date']);
        });

        DB::statement(
            'ALTER TABLE document_controle_runs ADD CONSTRAINT document_controle_runs_resultat_check '.
            "CHECK (resultat IN ('ok', 'echec', 'incomplet'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('document_controle_runs');
    }
};
