<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Champs « affaire » utilisés par le tableau de bord web (avocat).
     *
     * Tous nullable ou avec défaut : ils ne sont jamais envoyés par la sync
     * mobile (`DossierController`), qui continue de ne manipuler que son
     * sous-ensemble (`name`, `legal_domain`, `tag`, `color`…). `name` sert
     * d'objet du litige et `legal_domain` de matière côté web.
     */
    public function up(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->string('type')->default('contentieux')->after('user_id');
            $table->string('status')->default('ouvert')->after('tag');
            $table->string('internal_reference')->nullable()->after('status');
            $table->string('client_name')->nullable()->after('internal_reference');
            $table->string('client_role')->nullable()->after('client_name');
            $table->string('adverse_party')->nullable()->after('client_role');
            $table->string('jurisdiction')->nullable()->after('adverse_party');
            $table->string('nature')->nullable()->after('jurisdiction');
        });
    }

    public function down(): void
    {
        Schema::table('dossiers', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'status',
                'internal_reference',
                'client_name',
                'client_role',
                'adverse_party',
                'jurisdiction',
                'nature',
            ]);
        });
    }
};
