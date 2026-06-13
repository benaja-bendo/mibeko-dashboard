<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dossiers utilisateur synchronisés depuis les clients (mobile, web).
     *
     * Les identifiants sont générés côté client (UUID) afin de permettre la
     * création hors-ligne. `client_updated_at` (epoch ms, horloge client) sert
     * de référence pour la fusion last-write-wins ; `deleted_at` fait office de
     * tombstone pour propager les suppressions entre appareils.
     */
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('legal_domain')->default('Général');
            $table->string('tag', 32)->default('EN_COURS');
            $table->text('description')->nullable();
            $table->string('color', 9)->default('#1B3D2F');
            $table->bigInteger('client_created_at')->default(0);
            $table->bigInteger('client_updated_at')->default(0)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at']);
        });

        Schema::create('dossier_articles', function (Blueprint $table) {
            $table->foreignUuid('dossier_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('article_id')->constrained()->cascadeOnDelete();
            $table->text('personal_note')->nullable();
            $table->bigInteger('added_at')->default(0);

            $table->primary(['dossier_id', 'article_id']);
            $table->index('article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_articles');
        Schema::dropIfExists('dossiers');
    }
};
