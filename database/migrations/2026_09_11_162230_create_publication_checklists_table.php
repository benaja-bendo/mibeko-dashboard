<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preuve de validation (dashboard#119) : un enregistrement daté et rejouable à
 * chaque passage du garde-fou de publication (`PublicationGuardrail`), réussi
 * ou refusé — jamais une simple déclaration. Réalise l'étape 6 « Certificat »
 * de `docs/pipeline/protocole-validation.md` : acteur, critères vérifiés
 * (chiffrés, y compris les zéros) et version du document au moment du
 * contrôle, pour détecter une validation devenue obsolète.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_checklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('legal_documents')->cascadeOnDelete();
            // Nullable : un acteur système (commande console, job) peut ne
            // pas être un utilisateur authentifié.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_status', 20);
            // passed : critères satisfaits. blocked : refusé par le garde-fou.
            // forced : critères non satisfaits mais `force=true` assumé.
            $table->string('outcome', 20);
            $table->jsonb('criteria');
            // « Version » du document contrôlé : son `updated_at` au moment de
            // l'évaluation, pour prouver après coup qu'une modification
            // postérieure a rendu la preuve obsolète.
            $table->timestamp('document_snapshot_updated_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE publication_checklists ADD CONSTRAINT publication_checklists_outcome_check '.
            "CHECK (outcome IN ('passed', 'blocked', 'forced'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_checklists');
    }
};
