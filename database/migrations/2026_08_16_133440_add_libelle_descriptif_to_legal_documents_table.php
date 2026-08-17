<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campagne « défauts d'intitulé » du 16/08/2026 : 178 documents publiés portent
 * un titre réduit au type, au numéro et à la date — « Décret n° 2025-240 du
 * 20 juin 2025. » — et rien d'autre. Vérification faite contre les markdowns
 * MinerU (tirage de 10, 9 fidèles) : ces titres sont EXACTS. Le Journal officiel
 * publie ces nominations en « actes en abrégé », son sommaire n'annonce que
 * « Nomination. » et l'en-tête n'imprime aucun objet. Réécrire `titre_officiel`
 * fabriquerait donc un titre officiel qui n'existe nulle part — interdit par
 * docs/pipeline/correction-depuis-la-source.md, contrainte n° 1. Le détecteur
 * `mibeko:detecter-defauts-titres --famille=I1_acte_en_abrege` classe d'ailleurs
 * la famille en OBSERVATION, pas en défaut : le problème est de lisibilité
 * produit, pas de données.
 *
 * D'où ces deux colonnes, strictement ADDITIVES : `titre_officiel` reste la
 * source, fidèle et intouchée ; `libelle_descriptif` porte l'objet de l'acte
 * DÉRIVÉ de son corps (« Nomination d'un chargé de mission… »), pour les listes,
 * la recherche et le fil d'Ariane. Les deux ne se remplacent jamais l'un
 * l'autre : toute UI qui affiche le libellé doit laisser le titre officiel
 * accessible.
 *
 * `libelle_descriptif_source` existe pour que la provenance ne se perde pas —
 * un libellé tiré automatiquement du premier article n'a pas la même autorité
 * qu'un libellé écrit par un juriste. Sans cette colonne, les deux seraient
 * indiscernables six mois plus tard. Qui a écrit quoi et quand est déjà porté
 * par owen-it/auditing (la colonne n'est pas dans `$auditExclude`).
 */
return new class extends Migration
{
    /**
     * Provenances possibles du libellé.
     *
     * `article` : dérivé automatiquement du premier article par
     * `mibeko:proposer-libelles`, puis relu par un humain.
     * `manuel` : rédigé à la main par un éditeur dans le dashboard.
     */
    private const SOURCES = ['article', 'manuel'];

    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            $table->text('libelle_descriptif')->nullable()->after('titre_officiel');
            $table->string('libelle_descriptif_source', 20)->nullable()->after('libelle_descriptif');
        });

        // Garde-fou : une provenance ne se renseigne pas sans libellé, et un
        // libellé ne s'écrit pas sans dire d'où il vient — c'est toute la
        // raison d'être de la seconde colonne.
        DB::statement(
            'ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_libelle_descriptif_source_check '.
            'CHECK ((libelle_descriptif IS NULL AND libelle_descriptif_source IS NULL) '.
            'OR (libelle_descriptif IS NOT NULL AND libelle_descriptif_source IN ('.$this->sources().')))'
        );

        // Le libellé est un champ de recherche au même titre que le titre : sur
        // un acte en abrégé, c'est même le SEUL endroit où « nomination » ou
        // « chargé de mission » apparaît. L'index trigramme (pg_trgm, déjà
        // installé par la migration du 21/06) sert le `ILIKE '%…%'` de la
        // bibliothèque, qu'aucun index B-tree ne peut couvrir.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_legal_documents_libelle_descriptif_trgm '.
            'ON legal_documents USING gin (libelle_descriptif gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_legal_documents_libelle_descriptif_trgm');
        DB::statement('ALTER TABLE legal_documents DROP CONSTRAINT IF EXISTS legal_documents_libelle_descriptif_source_check');

        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropColumn(['libelle_descriptif', 'libelle_descriptif_source']);
        });
    }

    private function sources(): string
    {
        return collect(self::SOURCES)->map(fn (string $source) => "'{$source}'")->implode(', ');
    }
};
