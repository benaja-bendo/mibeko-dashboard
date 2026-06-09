<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le périmètre juridique (`legal_scope`) aux documents.
 *
 * Remplace l'ancienne heuristique côté client (détection "OHADA" dans le titre)
 * par un vrai champ structuré, filtrable côté serveur :
 *  - `national`      : droit interne congolais ;
 *  - `ohada`         : Actes uniformes / droit OHADA ;
 *  - `communautaire` : CEMAC, Union africaine et autres droits communautaires.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('legal_documents', 'legal_scope')) {
            return;
        }

        Schema::table('legal_documents', function (Blueprint $table) {
            $table->string('legal_scope', 20)->default('national')->index();
        });

        // Contrainte d'intégrité (cohérent avec les CHECK existants du schéma).
        DB::statement(
            'ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_legal_scope_check '.
            "CHECK (legal_scope IN ('national', 'ohada', 'communautaire'))"
        );
    }

    public function down(): void
    {
        if (! Schema::hasColumn('legal_documents', 'legal_scope')) {
            return;
        }

        DB::statement('ALTER TABLE legal_documents DROP CONSTRAINT IF EXISTS legal_documents_legal_scope_check');

        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropColumn('legal_scope');
        });
    }
};
