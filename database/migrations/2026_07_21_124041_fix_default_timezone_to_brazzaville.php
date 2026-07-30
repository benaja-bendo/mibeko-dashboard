<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le défaut historique `Africa/Kinshasa` (RDC) était une erreur de pays :
     * le marché cible est la République du Congo (Brazzaville). Les deux zones
     * IANA sont en UTC+1 permanent, la bascule des lignes existantes est donc
     * sans impact sur les horaires affichés.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Africa/Brazzaville')->change();
        });

        DB::table('user_settings')
            ->where('timezone', 'Africa/Kinshasa')
            ->update(['timezone' => 'Africa/Brazzaville']);
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Africa/Kinshasa')->change();
        });
    }
};
