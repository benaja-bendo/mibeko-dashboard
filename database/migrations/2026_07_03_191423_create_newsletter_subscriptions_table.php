<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inscriptions à la newsletter (site vitrine).
     *
     * Simple registre d'adresses opt-in. L'unicité de `email` garantit
     * l'idempotence de l'inscription (une même adresse ne crée pas de doublon) ;
     * `source` trace l'origine (page/campagne). Aucun envoi d'e-mail ici.
     */
    public function up(): void
    {
        Schema::create('newsletter_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('source')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscriptions');
    }
};
