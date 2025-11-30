<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_doc_id')->nullable()->constrained('legal_documents');
            $table->foreignUuid('target_doc_id')->nullable()->constrained('legal_documents');

            $table->foreignUuid('source_article_id')->nullable()->constrained('articles');
            $table->foreignUuid('target_article_id')->nullable()->constrained('articles');

            $table->string('relation_type', 50);
            $table->text('commentaire')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_relations');
    }
};
