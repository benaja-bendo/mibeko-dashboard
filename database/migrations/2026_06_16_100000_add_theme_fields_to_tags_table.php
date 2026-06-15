<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrichit la table `tags` pour qu'elle serve de taxonomie de « thèmes de
     * vie » : une icône, une description courte et un ordre d'affichage pour la
     * page « Parcourir par thème ». Le modèle Tag reste réutilisé (taggables +
     * recherche par slug existants), on ne crée pas de table dédiée.
     */
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->string('icon')->nullable()->after('slug');
            $table->string('description')->nullable()->after('icon');
            $table->unsignedInteger('display_order')->default(0)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn(['icon', 'description', 'display_order']);
        });
    }
};
