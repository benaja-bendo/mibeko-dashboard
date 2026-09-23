<?php

namespace App\Search;

use App\Jobs\LogSearchQuery;
use Illuminate\Http\Request;

/**
 * Point d'écriture unique du journal de recherche — mibeko-dashboard#111.
 *
 * Les cinq points d'entrée (library/search, library/suggest, search,
 * articles/search, legal-documents/search) appellent tous cette classe
 * plutôt que d'écrire `search_logs` chacun à sa façon, sinon la mesure
 * diverge d'une surface à l'autre. N'écrit jamais rien d'autre que
 * `search_logs`.
 *
 * Le journal mesure la demande : ce que quelqu'un a cherché, pas chaque
 * exécution du moteur (mibeko-dashboard#177). Mesuré en production le
 * 23/09/2026, 80 % des lignes venaient de liens `/textes?q=…` que le site
 * écrit lui-même (étapes des démarches, bouton « Rechercher » des
 * situations), parcourus par un robot toutes les 3 s — l'écran « requêtes
 * fréquentes » classait nos propres liens, pas les questions des usagers.
 */
class SearchQueryLogger
{
    /**
     * En-tête par lequel un client déclare que la recherche n'a pas été
     * saisie : elle suit un lien pré-rédigé. Ne pas journaliser sur sa seule
     * foi ne coûte rien — un client qui le forge ne fait qu'effacer ses
     * propres recherches de la mesure.
     */
    public const ORIGIN_HEADER = 'X-Mibeko-Search-Origin';

    public const ORIGIN_LINK = 'lien';

    public function log(string $surface, string $query, int $resultsCount, Request $request): void
    {
        $trimmed = trim($query);

        if ($trimmed === '' || ! $this->isUserSearch($trimmed, $request)) {
            return;
        }

        // Garde `sanctum` explicite : ces routes sont publiques (hors
        // `auth:sanctum`), `$request->user()` y interroge la garde par défaut
        // (`web`) et renvoyait null même avec un Bearer valide — 0 ligne sur
        // 1 076 rattachée à un usager en production le 23/09/2026.
        LogSearchQuery::dispatch($surface, $trimmed, $resultsCount, $request->user('sanctum')?->id);
    }

    private function isUserSearch(string $query, Request $request): bool
    {
        if (mb_strtolower(trim((string) $request->header(self::ORIGIN_HEADER))) === self::ORIGIN_LINK) {
            return false;
        }

        // Une page suivante relance la même recherche, elle n'en est pas une
        // nouvelle : la compter multiplierait chaque requête par le nombre de
        // pages parcourues.
        if ($request->integer('page', 1) > 1) {
            return false;
        }

        // Gabarit non substitué (`{search_term_string}` du `SearchAction`
        // JSON-LD du site) : des robots appellent l'URL modèle telle quelle.
        return preg_match('/^\{[a-z_]+\}$/i', $query) !== 1;
    }
}
