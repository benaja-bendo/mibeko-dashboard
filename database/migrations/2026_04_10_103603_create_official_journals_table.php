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
        Schema::create('official_journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->date('publication_date')->nullable();
            $table->string('file_path')->nullable()->comment('Chemin du fichier PDF dans MinIO');
            $table->string('transcription_status')->default('pending')->comment('Statut: pending, in_progress, completed, failed');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('official_journals');
    }
};
