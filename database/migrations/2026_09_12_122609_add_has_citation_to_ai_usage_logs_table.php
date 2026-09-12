<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue une réponse Assistant réussie AVEC citation d'une réponse
 * réussie sans source (dont `no_result`) — mibeko-dashboard#137.
 *
 * Nullable, jamais `false` par défaut : `NULL` = non applicable (route
 * autre que `assistant/chat`, ou statut autre que `success`), `false` =
 * réponse réussie mais sans aucune source citée — deux faits différents,
 * pas le même « non ». Posée uniquement par les deux points d'appel de
 * `AiUsageLogger::success()` dans `AiAssistantController` ; les appels
 * `library/explain`/`library/synthesis` (LibraryAiController) ne la posent
 * jamais et restent NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->boolean('has_citation')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn('has_citation');
        });
    }
};
