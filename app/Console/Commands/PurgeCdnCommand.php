<?php

namespace App\Console\Commands;

use App\Services\Cdn\CloudflarePurger;
use Illuminate\Console\Command;

/**
 * Purge manuelle du cache Cloudflare (benaja-bendo/mibeko-dashboard#161).
 *
 * Utile après une opération qui touche beaucoup de pages d'un coup et que les
 * déclencheurs automatiques (publication, article modifié, régénération de
 * slugs) n'ont pas vue en entier — typiquement après un lot de
 * `mibeko:corriger-slugs --execute` ou `mibeko:appliquer-numeros --execute`
 * lancé en dehors de la file d'attente HTTP.
 *
 * Synchrone (pas de mise en file) : l'opérateur voit le résultat de l'appel
 * Cloudflare immédiatement, sans devoir vérifier le journal après coup.
 *
 *   php artisan mibeko:purge-cdn
 */
class PurgeCdnCommand extends Command
{
    protected $signature = 'mibeko:purge-cdn';

    protected $description = 'Purge tout le cache Cloudflare devant mibeko.fr (no-op si le CDN n\'est pas configuré).';

    public function handle(CloudflarePurger $purger): int
    {
        if (! $purger->isConfigured()) {
            $this->warn('CLOUDFLARE_ZONE_ID / CLOUDFLARE_API_TOKEN absents : rien à purger (CDN pas encore basculé).');

            return self::SUCCESS;
        }

        $resultat = $purger->purgeEverything();

        if (! $resultat['success']) {
            $this->error("Purge échouée (HTTP {$resultat['status']}) : {$resultat['message']}");

            return self::FAILURE;
        }

        $this->info("Cache Cloudflare purgé (HTTP {$resultat['status']}).");

        return self::SUCCESS;
    }
}
