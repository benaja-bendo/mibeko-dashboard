<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('article_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('articles')->onDelete('cascade');

            $table->text('contenu_texte');

            $table->foreignUuid('modifie_par_document_id')->nullable()->constrained('legal_documents');

            $table->timestamps();
        });

        DB::statement('ALTER TABLE article_versions ADD COLUMN validity_period DATERANGE NOT NULL');
        DB::statement("ALTER TABLE article_versions ADD COLUMN search_tsv TSVECTOR GENERATED ALWAYS AS (to_tsvector('french', contenu_texte)) STORED");
        DB::statement('CREATE INDEX idx_versions_search ON article_versions USING GIN (search_tsv)');
        DB::statement('ALTER TABLE article_versions ADD CONSTRAINT article_versions_excl EXCLUDE USING GIST (article_id WITH =, validity_period WITH &&)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('article_versions');
    }
};
