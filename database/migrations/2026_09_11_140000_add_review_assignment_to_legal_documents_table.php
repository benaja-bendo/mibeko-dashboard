<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support de la file de revue (mibeko-front#33) : qui traite un document en
 * cours de curation, et depuis quand il est dans son statut courant.
 *
 * `curation_status_changed_at` est backfillée à `updated_at` pour les lignes
 * existantes — approximation raisonnable en l'absence d'historique dédié —
 * puis posée par `LegalDocument::booted()` à chaque transition réelle de
 * `curation_status`, seul point de passage déjà garanti valide par le
 * garde-fou de transition existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->foreignUuid('assigned_to')->nullable()->after('curation_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
            $table->timestamp('curation_status_changed_at')->nullable()->after('assigned_at');
        });

        DB::table('legal_documents')->update([
            'curation_status_changed_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropColumn('curation_status_changed_at');
            $table->dropColumn('assigned_at');
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
