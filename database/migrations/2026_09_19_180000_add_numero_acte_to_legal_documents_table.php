<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Décision du 19/09/2026 (`docs/decisions.md`, schéma d'URL du fonds) : l'URL
 * canonique d'un texte dérive désormais de sa CITATION — type, numéro, date de
 * signature — et non plus de son titre officiel, qui est un texte libre que la
 * curation corrige. Or le numéro d'acte n'existait dans aucun champ structuré :
 * `legal_documents` portait `type_code`, `date_signature`, `date_publication`,
 * `reference_nor`, `legal_scope`, et le numéro n'était lisible que DANS le
 * titre (« Décret n° 2025-240 du 20 juin 2025. »). Mesuré en production le
 * 19/09 : 810 des 1 087 textes publiés portent un numéro lisible, 945 ont une
 * date de signature, 773 ont les deux.
 *
 * Sans cette colonne, régénérer les slugs (dashboard#156) ferait dériver
 * l'identité publique d'un texte libre une seconde fois — c'est le préalable
 * non négociable de la décision, pas une commodité.
 *
 * `numero_acte_source` suit la doctrine de `libelle_descriptif_source`
 * (16/08/2026) : un numéro extrait automatiquement d'un titre OCR n'a pas
 * l'autorité d'un numéro saisi par un juriste qui a rouvert le Journal
 * officiel. Sans cette seconde colonne, les deux seraient indiscernables six
 * mois plus tard, au moment précis où l'on voudrait savoir à quoi se fier. Qui
 * a écrit quoi et quand reste porté par owen-it/auditing.
 *
 * Strictement ADDITIF : `titre_officiel` n'est pas touché, aucune écriture de
 * numéro n'en modifie une lettre.
 */
return new class extends Migration
{
    /**
     * Provenances possibles du numéro d'acte.
     *
     * `titre` : extrait du titre officiel par `mibeko:proposer-numeros`, puis
     * relu par un humain avant application.
     * `manuel` : saisi à la main par un éditeur, typiquement pour les textes
     * dont le titre a perdu sa référence et dont le numéro a été retrouvé
     * dans le corps de l'acte ou au JO.
     */
    private const SOURCES = ['titre', 'manuel'];

    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {
            // 60 caractères : le plus long numéro connu du corpus est un
            // arrêté à code de service (« 80-550/ETR-SGDAAPDP », 19), la marge
            // couvre les références à double service sans ouvrir un champ de
            // texte libre où une phrase pourrait se glisser.
            $table->string('numero_acte', 60)->nullable()->after('titre_officiel');
            $table->string('numero_acte_source', 20)->nullable()->after('numero_acte');
        });

        // Même garde-fou que pour le libellé : un numéro ne s'écrit pas sans
        // dire d'où il vient, et une provenance sans numéro ne veut rien dire.
        DB::statement(
            'ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_numero_acte_source_check '.
            'CHECK ((numero_acte IS NULL AND numero_acte_source IS NULL) '.
            'OR (numero_acte IS NOT NULL AND numero_acte_source IN ('.$this->sources().')))'
        );

        // La citation complète — (type, numéro, date de signature) — est la
        // clé d'identité du schéma d'URL : elle sert à retrouver un texte par
        // sa référence, et surtout à faire remonter les collisions avant toute
        // régénération de slug (5 paires de doublons vues le 19/09). Index
        // simple et non unique : l'unicité ne peut pas être imposée en base
        // tant que les doublons publiés ne sont pas fusionnés, et le cas du
        // décret 2025-279 et de ses statuts annexés montre qu'une citation
        // partagée peut être LÉGITIME — c'est une revue humaine, pas une
        // contrainte.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_legal_documents_citation '.
            'ON legal_documents (type_code, numero_acte, date_signature) '.
            'WHERE numero_acte IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_legal_documents_citation');
        DB::statement('ALTER TABLE legal_documents DROP CONSTRAINT IF EXISTS legal_documents_numero_acte_source_check');

        Schema::table('legal_documents', function (Blueprint $table) {
            $table->dropColumn(['numero_acte', 'numero_acte_source']);
        });
    }

    private function sources(): string
    {
        return collect(self::SOURCES)->map(fn (string $source) => "'{$source}'")->implode(', ');
    }
};
