<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contexte d'erreur du journal d'usage IA — mibeko-dashboard#84.
 *
 * Trois appels ont échoué en production le 04/09 sans qu'aucun diagnostic
 * en lecture seule (`pgsql_prod_ro`) ne puisse aller au-delà de « erreur,
 * fournisseur inconnu » : `provider`/`model` restent `null` sur une ligne en
 * échec, et rien ne portait la nature de l'erreur elle-même. Ces deux
 * colonnes existent pour que le PROCHAIN incident se lise depuis la base,
 * sans accès aux logs serveur ni au VPS.
 *
 * `error_message` est TRONQUÉ et NETTOYÉ avant écriture (voir
 * `AiUsageLogger::sanitizeErrorMessage()`) — jamais la pile d'appel complète,
 * qui peut porter un fragment de requête HTTP sortante (donc un secret) :
 * cette table est déjà consultée par plusieurs profils en lecture seule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->string('error_class', 255)->nullable()->after('status');
            $table->string('error_message', 500)->nullable()->after('error_class');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropColumn(['error_class', 'error_message']);
        });
    }
};
