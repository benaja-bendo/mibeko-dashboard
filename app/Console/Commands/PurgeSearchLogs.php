<?php

namespace App\Console\Commands;

use App\Models\SearchLog;
use Illuminate\Console\Command;

/**
 * Purge les recherches journalisées plus vieilles que l'horizon de rétention
 * — mibeko-dashboard#111. Rétention courte car la requête est une donnée
 * personnelle sensible (voir `config/search_logging.php`). Pas d'agrégat
 * préservé avant purge, contrairement à `mibeko:purge-product-events` : la
 * valeur de cet écran est un signal opérationnel récent, pas une BI
 * historique.
 */
class PurgeSearchLogs extends Command
{
    protected $signature = 'mibeko:purge-search-logs
        {--days= : Conserver les N derniers jours (config search_logging.retention_days par défaut)}';

    protected $description = 'Supprime les recherches journalisées plus vieilles que N jours.';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('search_logging.retention_days')));
        $threshold = now()->subDays($days);

        $deleted = SearchLog::where('created_at', '<', $threshold)->delete();

        $this->info("Recherches purgées (> {$days} j) : {$deleted}");

        return self::SUCCESS;
    }
}
