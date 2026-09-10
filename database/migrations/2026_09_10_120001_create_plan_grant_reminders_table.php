<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal d'envoi des rappels d'échéance d'abonnement — mibeko-dashboard#121.
     *
     * Même mécanique que `echeance_reminders` (dossiers) : la clé unique
     * (octroi, horizon, jour) garantit qu'un même rappel ne part jamais deux
     * fois, même si la command planifiée tourne plusieurs fois le même jour.
     */
    public function up(): void
    {
        Schema::create('plan_grant_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('plan_grant_id')->constrained('plan_grants')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset_days');
            $table->date('sent_on');
            $table->timestamps();

            $table->unique(['plan_grant_id', 'offset_days', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_grant_reminders');
    }
};
