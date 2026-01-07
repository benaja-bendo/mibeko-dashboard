<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enable required PostgreSQL extensions for Mibeko.
     *
     * - ltree: Hierarchical data (structure_nodes tree_path)
     * - btree_gist: Exclusion constraints on validity_period
     * - vector: pgvector for semantic embeddings
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS ltree');
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    }

    /**
     * Extensions are not dropped on rollback to prevent data loss.
     */
    public function down(): void
    {
        // Intentionally left empty - dropping extensions would destroy data
    }
};
