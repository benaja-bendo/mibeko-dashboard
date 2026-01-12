<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ajout de la colonne metadata et de son index
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->jsonb('metadata')->default('{}')->after('curation_status')
                ->comment('Métadonnées flexibles (ex: parties, avocats, chambre pour la Jurisprudence)');
        });

        // Index GIN pour la recherche performante dans le JSON
        DB::statement('CREATE INDEX idx_legal_docs_metadata ON legal_documents USING GIN (metadata)');

        // 2. Mise à jour des commentaires pour document_types (Documentation)
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('code', 10)->comment('LOI, DEC, ORD, CODE, CONST, JURIS (Jurisprudence)')->change();
            $table->integer('niveau_hierarchique')->default(0)->comment('1=Constitution, ... 10=Jurisprudence')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('code', 10)->comment(null)->change();
            $table->integer('niveau_hierarchique')->default(0)->comment(null)->change();
        });

        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
        
        // L'index est supprimé automatiquement avec la colonne, 
        // mais pour être propre en cas de rollback manuel :
        // DB::statement('DROP INDEX IF EXISTS idx_legal_docs_metadata');
    }
};
