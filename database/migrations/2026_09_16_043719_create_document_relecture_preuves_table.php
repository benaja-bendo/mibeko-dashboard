<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preuve de relecture dirigée (mibeko-dashboard#142, § 2.5 du plan « boîte
 * de réception », étape 4 de `docs/pipeline/protocole-validation.md`) : un
 * enregistrement daté et rejouable attestant qu'un éditeur a vu les points
 * d'observation obligatoires et réalisé le sondage attendus par un passage
 * précis du jeu de détecteurs (`document_controle_run_id`) — jamais une
 * simple déclaration.
 *
 * Distincte de `publication_checklists` (dashboard#119) : celle-ci trace
 * CHAQUE évaluation du garde-fou de publication (passée ou refusée) ;
 * celle-ci trace la RELECTURE HUMAINE elle-même, à un instant qui peut
 * précéder la publication de plusieurs étapes (`review → validated` n'exige
 * pas immédiatement `→ published`). Le garde-fou étendu (#142) lit cette
 * table pour savoir si une relecture couvrant le DERNIER contrôle existe.
 *
 * Append-only : une nouvelle relecture (ex. après un nouveau passage du jeu
 * de détecteurs) crée une nouvelle ligne, jamais une mise à jour — l'ancienne
 * reste comme trace de ce qui a été vu à l'époque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_relecture_preuves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('legal_documents')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            // Version PRÉCISE du contrôle couverte par cette relecture : une
            // preuve devient caduque dès qu'un passage plus récent du jeu de
            // détecteurs existe pour ce document (le garde-fou compare l'id).
            $table->foreignUuid('document_controle_run_id')->constrained('document_controle_runs')->cascadeOnDelete();
            // Articles des points d'observation obligatoires (premier, dernier,
            // deux autour de chaque rupture de séquence) que l'éditeur a vus.
            $table->jsonb('points_vus');
            // Les 15 articles tirés par le générateur déterministe (graine =
            // document_id + version du jeu) — jamais recalculés après coup :
            // consignés ici pour que le sondage reste vérifiable plus tard
            // même si le jeu de détecteurs évolue.
            $table->jsonb('sondage_articles');
            // Sous-ensemble du sondage effectivement confirmé par l'éditeur.
            $table->jsonb('sondage_confirmes');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_relecture_preuves');
    }
};
