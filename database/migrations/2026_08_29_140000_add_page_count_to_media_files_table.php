<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le canal de remplacement d'extraction accepte, pour chaque article, un repère
 * de page dans le PDF source. Rien ne vérifiait que cette page existe : une
 * cible annonçant `page: 412` sur un PDF de 64 pages passait sans un mot.
 *
 * C'est le défaut le plus banal d'une correction faite par une IA extérieure, et
 * le plus silencieux : le texte reste plausible, seul le repère ment, et l'erreur
 * ne se voit qu'en ouvrant le PDF à la page annoncée. Le contrôle était demandé
 * dès la conception du dossier de travail (mibeko-dashboard#69) mais s'est révélé
 * impossible — `media_files` ne portait ni nombre de pages ni dimensions.
 *
 * D'où cette colonne, strictement additive et volontairement nullable : elle se
 * remplit à l'ingestion pour les nouveaux PDF, et par rattrapage pour les
 * anciens. Tant qu'elle vaut NULL, le contrôle se tait plutôt que de refuser —
 * un rattrapage incomplet ne doit jamais bloquer la réparation d'un document.
 *
 * Les dimensions de page ne sont délibérément PAS stockées : elles varient d'une
 * page à l'autre, une seule valeur ne validerait donc rien de fiable, et le
 * rectangle reste de toute façon facultatif dans le format.
 *
 * ⚠️ Le schéma est piloté par les migrations Laravel, mais `mibeko-python` en
 * tient deux copies manuelles — le modèle SQLAlchemy `MediaFile` et
 * `schema_postgres.sql`. Elles sont mises à jour dans le même commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->integer('page_count')->nullable()->after('file_size');
        });

        // Un PDF a au moins une page. Zéro ou négatif ne serait pas une donnée
        // incomplète mais une donnée fausse, et le contrôle qui s'appuie dessus
        // refuserait alors toutes les pages du document.
        DB::statement(
            'ALTER TABLE media_files ADD CONSTRAINT media_files_page_count_check '.
            'CHECK (page_count IS NULL OR page_count > 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE media_files DROP CONSTRAINT IF EXISTS media_files_page_count_check');

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropColumn('page_count');
        });
    }
};
