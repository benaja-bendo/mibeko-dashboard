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
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "ltree"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "vector"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "btree_gist"');

        Schema::create('document_types', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('nom', 50);
            $table->integer('niveau_hierarchique')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
