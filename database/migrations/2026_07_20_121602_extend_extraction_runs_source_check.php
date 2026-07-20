<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * L'étage 3 de l'usine à textes (mibeko-python, structure_document) écrit
     * `STRUCTURATION_LLM` pour tracer les runs créés par le parseur heuristique
     * + Mistral. Le CHECK d'origine ne connaissait que le vocabulaire manuel
     * (MINERU, MANUAL_UPLOAD, PARSING) : tout run de l'usine échouait en
     * violation de contrainte.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE extraction_runs DROP CONSTRAINT IF EXISTS extraction_runs_source_check');
        DB::statement(
            'ALTER TABLE extraction_runs ADD CONSTRAINT extraction_runs_source_check '
            ."CHECK (source IN ('MINERU', 'MANUAL_UPLOAD', 'PARSING', 'STRUCTURATION_LLM'))"
        );
    }

    public function down(): void
    {
        DB::statement("DELETE FROM extraction_runs WHERE source = 'STRUCTURATION_LLM'");
        DB::statement('ALTER TABLE extraction_runs DROP CONSTRAINT IF EXISTS extraction_runs_source_check');
        DB::statement(
            'ALTER TABLE extraction_runs ADD CONSTRAINT extraction_runs_source_check '
            ."CHECK (source IN ('MINERU', 'MANUAL_UPLOAD', 'PARSING'))"
        );
    }
};
