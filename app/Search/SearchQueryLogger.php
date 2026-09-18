<?php

namespace App\Search;

use App\Jobs\LogSearchQuery;
use App\Models\User;

/**
 * Point d'écriture unique du journal de recherche — mibeko-dashboard#111.
 *
 * Les cinq points d'entrée (library/search, library/suggest, search,
 * articles/search, legal-documents/search) appellent tous cette classe
 * plutôt que d'écrire `search_logs` chacun à sa façon, sinon la mesure
 * diverge d'une surface à l'autre. N'écrit jamais rien d'autre que
 * `search_logs`.
 */
class SearchQueryLogger
{
    public function log(string $surface, string $query, int $resultsCount, ?User $user = null): void
    {
        $trimmed = trim($query);

        if ($trimmed === '') {
            return;
        }

        LogSearchQuery::dispatch($surface, $trimmed, $resultsCount, $user?->id);
    }
}
