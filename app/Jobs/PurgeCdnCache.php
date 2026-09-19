<?php

namespace App\Jobs;

use App\Services\Cdn\CloudflarePurger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Purge le cache Cloudflare devant mibeko.fr (benaja-bendo/mibeko-dashboard#161).
 *
 * `ShouldBeUnique` : le verrou est posé au moment de la mise en file — un
 * second `PurgeCdnCache::dispatch()` pendant que le premier attend son délai
 * d'une minute (`CdnPurgeScheduler`) est simplement ignoré, pas mis en
 * double. `uniqueFor` dépasse légèrement ce délai pour ne jamais laisser
 * passer un déclenchement entre la fin du verrou et l'exécution du job.
 */
class PurgeCdnCache implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function uniqueId(): string
    {
        return 'cdn-purge-everything';
    }

    public function uniqueFor(): int
    {
        return 90;
    }

    public function handle(CloudflarePurger $purger): void
    {
        $resultat = $purger->purgeEverything();

        if (! $resultat['success']) {
            // Le service a déjà journalisé le détail (zone, statut, message) :
            // l'exception ne fait que déclencher le retry du job.
            throw new \RuntimeException('Purge Cloudflare échouée : '.$resultat['message']);
        }
    }
}
