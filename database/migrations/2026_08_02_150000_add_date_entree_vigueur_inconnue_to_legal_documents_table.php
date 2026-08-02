<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit docs/audit-ingestion-2026-08-02.md, phase 3c : gate éditorial de
 * publication — un document sans date_entree_vigueur ne peut être publié
 * que si son absence est explicitement assumée, pas simplement oubliée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->boolean('date_entree_vigueur_inconnue')->default(false)->after('date_entree_vigueur');
        });
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropColumn('date_entree_vigueur_inconnue');
        });
    }
};
