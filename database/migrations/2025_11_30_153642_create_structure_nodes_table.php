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
        Schema::create('structure_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('legal_documents')->onDelete('cascade');

            $table->string('type_unite', 50);
            $table->string('numero', 50)->nullable();
            $table->text('titre')->nullable();

            $table->string('validation_status')->default('pending');
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });

        DB::statement('ALTER TABLE structure_nodes ADD COLUMN tree_path ltree NOT NULL');
        DB::statement('CREATE INDEX idx_structure_path ON structure_nodes USING GIST (tree_path)');
        DB::statement('CREATE INDEX idx_structure_doc ON structure_nodes (document_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('structure_nodes');
    }
};
