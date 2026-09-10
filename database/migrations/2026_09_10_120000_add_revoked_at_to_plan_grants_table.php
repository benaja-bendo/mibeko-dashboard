<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sépare la période contractuelle de la révocation effective — mibeko-dashboard#121.
     *
     * `revokeProPlan` (Admin\UserController) écrasait jusqu'ici `ends_at` avec
     * `now()` pour couper l'accès : la date de fin contractuelle d'origine —
     * celle qu'un justificatif doit afficher — disparaissait alors sans
     * laisser de trace. `ends_at` redevient une donnée purement contractuelle
     * (jamais réécrite après création), `revoked_at` porte la coupure d'accès
     * anticipée quand elle a lieu.
     */
    public function up(): void
    {
        Schema::table('plan_grants', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('plan_grants', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};
