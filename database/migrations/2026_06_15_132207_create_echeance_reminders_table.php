<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal d'envoi des rappels d'échéance.
     *
     * Garantit l'idempotence : la clé unique (échéance, horizon, jour) empêche
     * d'envoyer deux fois le même rappel, même si la command tourne plusieurs
     * fois dans la journée.
     */
    public function up(): void
    {
        Schema::create('echeance_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('echeance_id')->constrained('dossier_echeances')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset_days');
            $table->date('sent_on');
            $table->timestamps();

            $table->unique(['echeance_id', 'offset_days', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('echeance_reminders');
    }
};
