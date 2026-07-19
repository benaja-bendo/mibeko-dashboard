<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Annexes d'un dossier : trois collections jusqu'ici gardées en localStorage
     * côté web (références juridiques, pièces, documents générés), désormais
     * persistées côté serveur pour un accès multi-appareils.
     *
     * FK `dossier_id` en cascade : les annexes disparaissent avec leur dossier.
     * Identifiants UUID générés côté serveur, comme les autres modèles.
     */
    public function up(): void
    {
        // Références juridiques (article/document de la Bibliothèque) rattachées.
        // `target_id` est l'UUID de l'article/document visé ; il tient lieu
        // d'identité pour le web (LegalReference.id) et garantit l'idempotence :
        // ajouter deux fois la même cible dans un dossier ne crée pas de doublon.
        Schema::create('dossier_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dossier_id')->constrained()->cascadeOnDelete();
            $table->uuid('target_id');
            $table->string('type');
            $table->string('title');
            $table->string('breadcrumb')->nullable();
            $table->string('number')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['dossier_id', 'target_id']);
        });

        // Pièces versées : métadonnées uniquement (aucun binaire à cette étape).
        // `added_at` (horodatage d'ajout au dossier) est exposé au web comme
        // `addedAt` ; il est renseigné par le serveur à la création.
        Schema::create('dossier_pieces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dossier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->bigInteger('size');
            $table->string('mime');
            $table->text('note')->nullable();
            $table->timestamp('added_at');
            $table->timestamps();

            $table->index('dossier_id');
        });

        // Documents générés depuis un modèle (mise en demeure, statuts…),
        // avec leur contenu HTML imprimable. `created_at` (Eloquent) est exposé
        // au web comme `createdAt`.
        Schema::create('dossier_generated_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('dossier_id')->constrained()->cascadeOnDelete();
            $table->string('template_id');
            $table->string('template_name');
            $table->string('title');
            $table->longText('html');
            $table->timestamps();

            $table->index('dossier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_generated_documents');
        Schema::dropIfExists('dossier_pieces');
        Schema::dropIfExists('dossier_references');
    }
};
