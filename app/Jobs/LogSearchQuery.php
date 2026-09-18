<?php

namespace App\Jobs;

use App\Models\SearchLog;
use App\Search\SearchQueryLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Écriture différée d'une ligne de `search_logs` — mibeko-dashboard#111.
 *
 * `library/search` est public et déjà sous quota dédié (`throttle:search_public`) :
 * la journalisation ne doit jamais retarder la réponse d'une recherche, donc
 * elle est mise en file par {@see SearchQueryLogger} plutôt
 * qu'écrite en synchrone dans la requête HTTP.
 */
class LogSearchQuery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $surface,
        public readonly string $query,
        public readonly int $resultsCount,
        public readonly ?string $userId,
    ) {}

    public function handle(): void
    {
        SearchLog::create([
            'user_id' => $this->userId,
            // Normalisée ici (trim + minuscule) : deux frappes qui ne
            // diffèrent que par la casse ou des espaces superflus doivent
            // compter comme la même requête dans l'agrégat de fréquence.
            // Plafonnée à 255 caractères (colonne `query`), cf. l'incident
            // AiRouteName sur un varchar débordé.
            'query' => mb_substr(mb_strtolower(trim($this->query)), 0, 255),
            'results_count' => $this->resultsCount,
            'surface' => $this->surface,
        ]);
    }
}
