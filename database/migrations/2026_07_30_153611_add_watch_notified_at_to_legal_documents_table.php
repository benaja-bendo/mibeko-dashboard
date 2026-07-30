<?php

use App\Models\LegalDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marqueur d'idempotence de la veille légale.
     *
     * `watch_notified_at` retient la date à laquelle un document a été ANNONCÉ
     * aux abonnés. Une colonne plutôt qu'une table journal (patron
     * `echeance_reminders`) parce que la clé naturelle est ici le document seul :
     * un journal `(document_id)` unique porterait exactement la même information
     * au prix d'une table et d'une clé étrangère de plus à faire cascader lors des
     * suppressions définitives. La colonne permet en outre de réserver les
     * documents à annoncer dans la même requête que leur sélection
     * (`whereNull('watch_notified_at')` + `UPDATE` verrouillé), ce qui rend la
     * réservation atomique face à deux publications concurrentes.
     */
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->timestamp('watch_notified_at')->nullable()->after('curation_status');

            // Le producteur ne cherche QUE les documents publiés jamais annoncés :
            // index composite aligné sur exactement cette requête.
            $table->index(['curation_status', 'watch_notified_at'], 'legal_documents_watch_idx');
        });

        // Le corpus déjà publié est considéré comme DÉJÀ annoncé : sans ce
        // rattrapage, la première republication d'un texte historique
        // déclencherait une alerte pour un texte vieux de plusieurs mois, et un
        // traitement de masse sur le fonds existant noierait les abonnés.
        DB::table('legal_documents')
            ->where('curation_status', LegalDocument::STATUS_PUBLISHED)
            ->whereNull('watch_notified_at')
            ->update(['watch_notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropIndex('legal_documents_watch_idx');
            $table->dropColumn('watch_notified_at');
        });
    }
};
