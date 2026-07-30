<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal de diffusion de la veille légale.
     *
     * POURQUOI UN JOURNAL EN PLUS DU MARQUEUR `watch_notified_at`
     * -----------------------------------------------------------
     * Le marqueur porté par le document répond à « ce texte a-t-il déjà été
     * réservé ? » ; il ne dit rien de « l'alerte est-elle réellement partie ? ».
     * Comme la réservation précède l'envoi (choix assumé : mieux vaut perdre une
     * alerte que la doubler), un échec définitif du job laissait un texte marqué
     * comme annoncé sans qu'aucun abonné n'ait rien reçu — sans trace, et sans
     * aucun moyen de reprise puisque le texte ne serait plus jamais candidat.
     *
     * Chaque lot réservé crée donc ici une ligne, DANS LA MÊME TRANSACTION que
     * la pose de `watch_notified_at` : une réservation sans ligne de journal est
     * impossible, et l'ensemble des alertes non diffusées se lit d'une requête
     * (`status <> 'delivered'`). `mibeko:retry-legal-watch` rejoue ces lignes.
     *
     * Les horodatages d'étape (`in_app_written_at`, `pushes_dispatched_at`)
     * rendent le rejeu partiel sûr : un job repris ne refait que l'étape qui
     * n'avait pas abouti. Rien n'est jamais supprimé de cette table : elle vaut
     * piste d'audit de ce qui a été annoncé, à qui et quand.
     */
    public function up(): void
    {
        Schema::create('legal_watch_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Le lot réservé. Une colonne JSON plutôt qu'une table de liaison :
            // la liste n'est jamais requêtée par document (le rattrapage part
            // toujours du lot), et la synthèse traite le lot comme un tout.
            $table->json('document_ids');
            $table->unsignedInteger('document_count');

            // pending → delivered (nominal) | failed (échec définitif du job).
            $table->string('status', 20)->default('pending');

            $table->timestamp('in_app_written_at')->nullable();
            $table->timestamp('pushes_dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            // Requête exacte du rattrapage : les lots non diffusés, les plus
            // anciens d'abord.
            $table->index(['status', 'created_at'], 'legal_watch_dispatches_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_watch_dispatches');
    }
};
