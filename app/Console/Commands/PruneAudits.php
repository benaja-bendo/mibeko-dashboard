<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OwenIt\Auditing\Models\Audit;

/**
 * Purge les entrées d'audit plus vieilles qu'un horizon de rétention.
 *
 * Planifiée mensuellement (conserver 365 jours par défaut). La purge manuelle
 * de l'AuditController applique la même règle de seuil.
 */
class PruneAudits extends Command
{
    protected $signature = 'mibeko:prune-audits {--days=365 : Conserver les entrées des N derniers jours}';

    protected $description = 'Supprime les entrées du journal d\'audit plus vieilles que N jours.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = now()->subDays($days);

        $deleted = Audit::where('created_at', '<', $threshold)->delete();

        $this->info("Entrées d'audit purgées (> {$days} j) : {$deleted}");

        return self::SUCCESS;
    }
}
