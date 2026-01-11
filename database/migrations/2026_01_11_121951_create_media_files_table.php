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
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('legal_documents')->onDelete('cascade');
            $table->string('file_path', 255); // Chemin S3 ou local
            $table->string('mime_type', 100)->nullable(); // application/pdf
            $table->bigInteger('file_size')->nullable();
            $table->string('description', 255)->nullable(); // "Original signé", "Annexe 1"
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
