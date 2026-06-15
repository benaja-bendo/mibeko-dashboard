<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations d'équipe : un admin pré-enregistre une adresse + des rôles ;
     * l'invité finalise son compte via un lien tokenisé (page front
     * `/auth/accept-invitation`). Le token est stocké hashé (jamais en clair),
     * comme pour les jetons de réinitialisation de mot de passe.
     */
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('token');
            $table->json('roles');
            $table->foreignUuid('invited_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            // Une seule invitation active par adresse (les acceptées/expirées
            // sont conservées pour l'historique mais ne bloquent pas un renvoi).
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
