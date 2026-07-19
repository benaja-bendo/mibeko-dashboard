<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Le replay non-destructif (mibeko-python, execute_parsing_task et
     * endpoints runs diff/promote/discard) écrit `needs_review` (proposition
     * parquée en staging, en attente d'arbitrage) et `discarded` (proposition
     * rejetée). Le CHECK d'origine ne connaissait que le vocabulaire MinerU :
     * tout replay sur un document déjà curé échouait en violation de
     * contrainte.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE extraction_runs DROP CONSTRAINT IF EXISTS extraction_runs_status_check');
        DB::statement(
            'ALTER TABLE extraction_runs ADD CONSTRAINT extraction_runs_status_check '
            ."CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'partial', 'needs_review', 'discarded'))"
        );
    }

    public function down(): void
    {
        // Replie d'abord les statuts du replay vers le vocabulaire d'origine,
        // sinon la contrainte restreinte ne peut pas être recréée. La
        // proposition reste dans meta (staged/discarded_at) : seul le libellé
        // du statut est perdu.
        DB::statement("UPDATE extraction_runs SET status = 'succeeded' WHERE status = 'needs_review'");
        DB::statement("UPDATE extraction_runs SET status = 'failed' WHERE status = 'discarded'");
        DB::statement('ALTER TABLE extraction_runs DROP CONSTRAINT IF EXISTS extraction_runs_status_check');
        DB::statement(
            'ALTER TABLE extraction_runs ADD CONSTRAINT extraction_runs_status_check '
            ."CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'partial'))"
        );
    }
};
