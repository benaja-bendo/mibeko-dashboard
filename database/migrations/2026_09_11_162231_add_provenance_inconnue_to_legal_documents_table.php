<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard#119 : même doctrine que `date_entree_vigueur_inconnue`
 * (migration du 02/08/2026, phase 3c) — un document sans provenance
 * (`metadata.source_url`/`fetched_at`) ne peut être publié que si son
 * absence est explicitement assumée par un éditeur, pas simplement oubliée.
 * Couvre l'import manuel (`ingestion_mode: web_upload`), qui ne capture
 * aucune source externe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->boolean('provenance_inconnue')->default(false)->after('date_entree_vigueur_inconnue');
        });
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropColumn('provenance_inconnue');
        });
    }
};
