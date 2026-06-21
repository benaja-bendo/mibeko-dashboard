<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Active la recherche floue (trigram) pour la Bibliothèque.
 *
 * Le stemmer français unifie une partie d'une famille de mots (dote → dot) mais
 * pas tout (dotal/dotale → dotal, distinct de dot). Résultat : une recherche
 * « dote » ne remontait pas un article parlant de « régime dotal ». On ajoute un
 * filet `strict_word_similarity` (extension pg_trgm) insensible aux accents
 * (`unaccent`), branché en OR de la recherche full-text via l'opérateur
 * indexable `%>>`, qui rattrape ces variantes morphologiques et les fautes de
 * frappe SANS toucher au `search_tsv`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Extensions de recherche floue / normalisation des accents.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        // Wrapper IMMUTABLE autour de unaccent() : la forme à deux arguments
        // (dictionnaire explicite) est nécessaire pour pouvoir marquer la
        // fonction IMMUTABLE et l'utiliser dans un index fonctionnel.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE PARALLEL SAFE STRICT
            AS $func$ SELECT public.unaccent('public.unaccent', $1) $func$
        SQL);

        // Index GIN trigram sur le contenu désaccentué : accélère l'opérateur
        // `%>>` (strict_word_similarity) utilisé par le filet flou de la biblio.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_versions_content_trgm ON article_versions USING gin (f_unaccent(contenu_texte) gin_trgm_ops)');

        // Seuil par défaut du filet flou au niveau de la base : l'opérateur
        // `%>>` l'utilise sur les nouvelles connexions. 0.35 garde « dotal »
        // (0.375) et écarte « dotation » (0.27) pour une recherche « dote ».
        // (Le code le refixe aussi par requête, pour les connexions déjà ouvertes.)
        DB::statement('ALTER DATABASE '.$this->quotedDatabaseName().' SET pg_trgm.strict_word_similarity_threshold = 0.35');
    }

    public function down(): void
    {
        DB::statement('ALTER DATABASE '.$this->quotedDatabaseName().' RESET pg_trgm.strict_word_similarity_threshold');
        DB::statement('DROP INDEX IF EXISTS idx_versions_content_trgm');
        DB::statement('DROP FUNCTION IF EXISTS f_unaccent(text)');
        // Les extensions pg_trgm / unaccent peuvent servir ailleurs : on ne les
        // supprime pas pour éviter de casser d'autres fonctionnalités.
    }

    /**
     * Nom de la base courante, correctement entre guillemets pour ALTER DATABASE.
     */
    private function quotedDatabaseName(): string
    {
        return '"'.str_replace('"', '""', (string) DB::getDatabaseName()).'"';
    }
};
