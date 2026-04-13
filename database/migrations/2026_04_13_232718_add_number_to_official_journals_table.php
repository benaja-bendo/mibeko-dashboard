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
        Schema::table('official_journals', function (Blueprint $table) {
            $table->string('number')->nullable()->after('title')->comment('Numéro du journal officiel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('official_journals', function (Blueprint $table) {
            $table->dropColumn('number');
        });
    }
};
