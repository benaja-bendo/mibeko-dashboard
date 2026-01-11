<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. S'assurer que les extensions sont activées
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp";');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "vector";');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "btree_gist";');

        // 2. Création de la table avec les types standards
        Schema::create('article_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));

            $table->foreignUuid('article_id')
                ->constrained('articles')
                ->onDelete('cascade');

            // Le type DATERANGE sera ajouté via raw SQL plus bas

            $table->text('contenu_texte');
            $table->text('embedding_context')->nullable()->comment('Le texte enrichi (Code > Titre > Contenu) utilisé pour générer le vecteur');

            // Le type VECTOR sera ajouté via raw SQL

            // Le type TSVECTOR pour le Full Text Search

            $table->foreignUuid('modifie_par_document_id')
                ->nullable()
                ->constrained('legal_documents');

            $table->string('validation_status')->default('pending');
            $table->boolean('is_verified')->default(false);

            $table->timestamps();
        });

        // 3. Ajout des colonnes complexes et contraintes via RAW SQL
        DB::statement('
            ALTER TABLE article_versions
            ADD COLUMN validity_period DATERANGE NOT NULL,
            ADD COLUMN embedding vector(1536),
            ADD COLUMN search_tsv tsvector;
        ');

        // 4. Ajout de la contrainte d'exclusion (Empêcher le chevauchement de dates)
        DB::statement('
            ALTER TABLE article_versions
            ADD CONSTRAINT article_versions_no_overlap
            EXCLUDE USING GIST (
                article_id WITH =,
                validity_period WITH &&
            );
        ');

        // 5. Création des Index

        // Index Full Text
        DB::statement('CREATE INDEX idx_versions_search ON article_versions USING GIN(search_tsv);');

        // Index Vectoriel (HNSW) - Le plus important pour ton RAG
        DB::statement('
            CREATE INDEX idx_versions_embedding 
            ON article_versions 
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64);
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('article_versions');
    }
};
