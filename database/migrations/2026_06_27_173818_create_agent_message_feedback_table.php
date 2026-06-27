<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_message_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Référence le message évalué (table du package laravel/ai, sans FK
            // déclarée côté package : on garde un simple index).
            $table->string('message_id', 36)->index();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Avis : 'up' (pouce haut) ou 'down' (pouce bas).
            $table->string('rating', 4);
            // Commentaire libre facultatif (motif d'un pouce bas, suggestion…).
            $table->text('comment')->nullable();
            $table->timestamps();

            // Un seul avis par utilisateur et par message (upsert).
            $table->unique(['message_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_message_feedback');
    }
};
