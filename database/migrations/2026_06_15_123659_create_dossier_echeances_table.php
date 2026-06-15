<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Échéances d'un dossier (audiences, délais de procédure, prescriptions…).
     *
     * Identifiant UUID généré côté client et `client_updated_at` (epoch ms)
     * prévus pour une future synchronisation multi-appareils alignée sur celle
     * des dossiers ; pour l'instant alimentées uniquement par le web.
     * Les colonnes `trigger_*`, `rule_id` et `basis_article_id` sont réservées
     * au calculateur de délais (Palier 2) et restent nulles au Palier 1.
     */
    public function up(): void
    {
        Schema::create('dossier_echeances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dossier_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('autre');
            $table->string('title');
            $table->date('due_date')->nullable();
            $table->string('status')->default('a_venir');
            $table->string('trigger_event')->nullable();
            $table->date('trigger_date')->nullable();
            $table->string('rule_id')->nullable();
            $table->foreignUuid('basis_article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->boolean('is_confirmed')->default(false);
            $table->json('reminders')->nullable();
            $table->text('note')->nullable();
            $table->bigInteger('client_created_at')->default(0);
            $table->bigInteger('client_updated_at')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['dossier_id', 'deleted_at']);
            $table->index(['due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_echeances');
    }
};
