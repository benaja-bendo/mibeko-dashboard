<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trace la résolution d'un signalement : quand et par quel admin, pour
     * disposer d'un historique de triage exploitable côté administration.
     */
    public function up(): void
    {
        Schema::table('curation_flags', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('resolved');
            $table->foreignUuid('resolved_by')->nullable()->after('resolved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('curation_flags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn('resolved_at');
        });
    }
};
