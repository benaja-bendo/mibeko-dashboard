<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * File de travaux de l'usine à textes (mibeko-python#22, § 3.3 du plan
 * « boîte de réception », docs/pipeline/plan-boite-de-reception-2026-09.md).
 *
 * `extraction_runs.document_id` est NOT NULL : un run ne peut pas exister
 * avant le document, or un dépôt est un travail AVANT d'être un document (et
 * un Journal officiel en produit plusieurs). D'où cette table dédiée, prise
 * en charge par le worker Python via `SELECT … FOR UPDATE SKIP LOCKED`.
 *
 * `step` et `status` sont séparés : une relance reprend à l'étape échouée,
 * jamais depuis le début. `fencing_token` empêche un worker dont le bail a
 * expiré d'écrire après qu'un autre a repris le travail — incrémenté à
 * chaque prise de bail, revérifié avant toute écriture finale.
 *
 * Ce lot ne crée QUE la migration et le modèle Eloquent minimal : la
 * logique de réservation/exécution vit côté worker Python
 * (mibeko-python#23, qui dépend de ce lot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 20);
            $table->string('manifest_id')->nullable();
            $table->string('step', 20)->default('recu');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->timestamp('locked_at')->nullable();
            // hôte:pid du worker qui détient le bail — jamais un identifiant
            // opaque : un incident doit pouvoir remonter au processus exact.
            $table->string('locked_by')->nullable();
            $table->bigInteger('fencing_token')->default(0);
            $table->text('last_error')->nullable();
            $table->string('error_class', 30)->nullable();
            $table->jsonb('result')->default('{}');
            // Identifiant libre (UUID d'utilisateur ou étiquette système
            // « MibekoBot/veille ») : pas de clé étrangère vers `users`, un
            // travail de veille n'a pas d'utilisateur authentifié à l'origine.
            $table->string('requested_by')->nullable();
            $table->timestamps();

            $table->index('manifest_id');
            // Requête de réservation du worker : documents éligibles triés
            // par ancienneté, à statut égal.
            $table->index(['status', 'step', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE ingestion_jobs ADD CONSTRAINT ingestion_jobs_kind_check '.
            "CHECK (kind IN ('depot', 'veille', 'reprise'))"
        );
        DB::statement(
            'ALTER TABLE ingestion_jobs ADD CONSTRAINT ingestion_jobs_step_check '.
            "CHECK (step IN ('recu', 'parse', 'structure', 'controle', 'termine'))"
        );
        DB::statement(
            'ALTER TABLE ingestion_jobs ADD CONSTRAINT ingestion_jobs_status_check '.
            "CHECK (status IN ('pending', 'running', 'failed', 'done'))"
        );
        DB::statement(
            'ALTER TABLE ingestion_jobs ADD CONSTRAINT ingestion_jobs_error_class_check '.
            "CHECK (error_class IS NULL OR error_class IN ('transitoire', 'definitive', 'information_manquante'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_jobs');
    }
};
