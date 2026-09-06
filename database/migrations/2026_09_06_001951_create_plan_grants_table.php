<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le registre des abonnements Pro vendus à la main — mibeko-dashboard#100.
     *
     * Jusqu'ici, le seul mécanisme qui rend un compte Pro dans les faits est
     * l'attribution du rôle Spatie `user_pro`, à la main, sans date de fin,
     * sans montant, sans canal : un abonné mensuel payé une fois reste Pro à
     * vie, et rien ne trace la vente elle-même. Cette table ne remplace pas
     * le rôle (les autres portes d'accès — quota IA, garde-fous front — en
     * dépendent encore) : elle donne au plan résolu par
     * `EntitlementsResolver` une source de vérité VIVANTE et bornée dans le
     * temps, vérifiée à chaque lecture plutôt que déduite d'un rôle qui ne
     * se retire jamais tout seul.
     */
    public function up(): void
    {
        Schema::create('plan_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Un seul plan payant existe aujourd'hui ('pro'), mais nommer la
            // colonne évite qu'une table conçue pour un octroi temporel serve
            // de canal détourné pour autre chose demain sans le dire.
            $table->string('plan', 20)->default('pro');
            $table->timestamp('starts_at')->useCurrent();
            // Jamais nulle : un octroi sans échéance reproduirait exactement
            // le défaut du rôle attribué à la main qu'on corrige ici.
            $table->timestamp('ends_at');
            $table->unsignedInteger('amount_fcfa')->nullable();
            // Texte libre plutôt qu'une énumération contrainte : saisi par un
            // seul opérateur (le fondateur), le rail réel (mobile money,
            // espèces…) peut varier sans qu'une migration soit nécessaire.
            $table->string('channel', 40)->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Requête à chaque résolution d'entitlements et de palier de
            // quota (AiUserQuotaTier::tierFor) : la lecture live remplace un
            // job planifié, l'index la garde bon marché.
            $table->index(['user_id', 'plan', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_grants');
    }
};
