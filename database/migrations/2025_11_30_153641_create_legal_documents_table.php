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
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type_code', 10);
            $table->foreign('type_code')->references('code')->on('document_types');
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions');

            $table->text('titre_officiel');
            $table->string('reference_nor', 50)->nullable();

            $table->date('date_signature')->nullable();
            $table->date('date_publication')->nullable();
            $table->date('date_entree_vigueur')->nullable();

            $table->text('source_url')->nullable();
            $table->string('statut', 20)->default('vigueur');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
