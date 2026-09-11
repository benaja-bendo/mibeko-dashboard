<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trace l'auteur d'un signalement humain (ex : demande de correction
     * transmise depuis la file de revue, mibeko-front#33) — symétrique de
     * `resolved_by`, absent jusqu'ici : seuls les détecteurs automatiques
     * créaient des signalements sans qu'un utilisateur en soit l'auteur.
     */
    public function up(): void
    {
        Schema::table('curation_flags', function (Blueprint $table) {
            $table->foreignUuid('created_by')->nullable()->after('source')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('curation_flags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
