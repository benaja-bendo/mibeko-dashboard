<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complète `ingestion_provenances` avec les 9 champs de `ManifestEntry`
 * (mibeko-python, src/acquisition/manifest.py) qui manquaient à la création
 * de la table (dashboard#140) : elle ne portait que de quoi dédoublonner par
 * SHA-256, pas de quoi reconstruire une entrée de manifeste complète
 * (mibeko-python#28, reliquat #23, § 3.7 du plan « boîte de réception »).
 *
 * Toutes nullables : les lignes déjà écrites (dédoublonnage SHA-256 seul,
 * depuis le 14/09/2026) ne les portent pas, et aucun backfill rétroactif
 * n'est possible pour `fichier`/`size_bytes` sans revenir aux JSONL sources —
 * l'export périodique (ce même ticket) les servira désormais forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestion_provenances', function (Blueprint $table) {
            $table->string('fichier')->nullable()->after('type_source');
            $table->string('statut', 30)->nullable()->after('fichier');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('statut');
            $table->string('jo_numero')->nullable()->after('source_url');
            $table->date('jo_date')->nullable()->after('jo_numero');
            $table->unsignedSmallInteger('jo_annee')->nullable()->after('jo_date');
            $table->text('titre')->nullable()->after('jo_annee');
            $table->boolean('retroactif')->default(false)->after('titre');
            // Ids sœurs (même jo_annee/jo_numero, arbitrage humain) — même
            // forme que `ManifestEntry.variantes_multiples` (liste de str),
            // jamais interrogée par colonne : jsonb par simplicité, pas par
            // besoin de requêter dedans.
            $table->jsonb('variantes_multiples')->nullable()->after('retroactif');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_provenances', function (Blueprint $table) {
            $table->dropColumn([
                'fichier',
                'statut',
                'size_bytes',
                'jo_numero',
                'jo_date',
                'jo_annee',
                'titre',
                'retroactif',
                'variantes_multiples',
            ]);
        });
    }
};
