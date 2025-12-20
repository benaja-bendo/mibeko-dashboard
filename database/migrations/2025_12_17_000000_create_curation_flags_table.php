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
        Schema::create('curation_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('document_id')->nullable()->constrained('legal_documents');

            $table->uuid('document_id')->nullable();
            $table->foreign('document_id')->references('id')->on('legal_documents');

            $table->uuid('article_id')->nullable();
            $table->foreign('article_id')->references('id')->on('articles');

            $table->string('type_probleme', 50)->nullable(); // 'scan_illisible', 'structure_cassee', 'doublon'
            $table->text('description')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('curation_flags');
    }
};
