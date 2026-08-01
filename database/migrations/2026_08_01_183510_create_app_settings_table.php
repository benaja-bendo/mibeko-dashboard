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
        // Table clé-valeur générique pour les réglages qu'il faut pouvoir
        // changer à l'exécution sans redéployer (contrairement à config/*.php).
        // Premier usage : mobile.latest_version, mis à jour par la CI de
        // mibeko-app-kmp après une publication Play Store en production.
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
