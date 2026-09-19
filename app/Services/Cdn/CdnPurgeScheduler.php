<?php

namespace App\Services\Cdn;

use App\Jobs\PurgeCdnCache;

/**
 * Point d'appel unique pour tous les déclencheurs de purge CDN : publication
 * d'un texte, modification d'un article publié, régénération de slugs,
 * application de libellés (benaja-bendo/mibeko-dashboard#161).
 *
 * Différée d'une minute et rendue UNIQUE (`PurgeCdnCache::uniqueId()`) :
 * plusieurs déclenchements rapprochés (une rafale de PATCH, un lot de
 * `mibeko:appliquer-libelles`) ne doivent produire qu'une seule purge, pas
 * une par document. La purge étant `purge_everything`, la première mise en
 * file couvre déjà tout ce que les suivantes auraient demandé.
 *
 * No-op silencieux quand le CDN n'est pas configuré : ne met même pas la
 * file en jeu, pour ne rien faire tourner en pure perte tant que
 * vps_infra#1 n'est pas basculé.
 */
class CdnPurgeScheduler
{
    public function __construct(private CloudflarePurger $purger) {}

    public function scheduleAsync(): void
    {
        if (! $this->purger->isConfigured()) {
            return;
        }

        PurgeCdnCache::dispatch()->delay(now()->addMinute());
    }
}
