<?php

namespace App\Services\Cdn;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Purge le cache Cloudflare devant mibeko.fr (benaja-bendo/vps_infra#1,
 * étape E8 du plan CDN). L'offre gratuite ne purge pas par préfixe ni par
 * étiquette — seulement tout ou rien (`purge_everything`). Purger tout est
 * acceptable ici : la publication d'un texte est rare (quelques par jour au
 * plus), l'origine tient une remise en cache progressive (mibeko-site#46,
 * TTFB ~200-500 ms), et les actifs `/_astro/*` sont immuables — ils se
 * rechargent à l'identique.
 *
 * Sans `services.cloudflare.zone_id`/`api_token`, la classe est un NO-OP
 * SILENCIEUX : le chantier CDN n'est pas encore basculé (vps_infra#1,
 * étapes E0-E6), et rien ne doit échouer ni journaliser d'erreur en
 * attendant — chaque appelant (job automatique, commande manuelle) partage
 * ce même garde-fou plutôt que de le dupliquer.
 */
class CloudflarePurger
{
    public function isConfigured(): bool
    {
        return filled(config('services.cloudflare.zone_id'))
            && filled(config('services.cloudflare.api_token'));
    }

    /**
     * Purge tout le cache de la zone. Ne lève jamais : le résultat dit ce
     * qui s'est passé, à l'appelant (job avec retry, commande avec code de
     * sortie) de décider quoi en faire.
     *
     * @return array{success: bool, status: ?int, message: string}
     */
    public function purgeEverything(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => true, 'status' => null, 'message' => 'non configuré — no-op'];
        }

        $zone = (string) config('services.cloudflare.zone_id');

        try {
            $response = Http::withToken((string) config('services.cloudflare.api_token'))
                ->acceptJson()
                ->timeout(15)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", [
                    'purge_everything' => true,
                ]);
        } catch (\Throwable $e) {
            Log::error('[cdn] purge Cloudflare : réseau injoignable', ['message' => $e->getMessage()]);

            return ['success' => false, 'status' => null, 'message' => $e->getMessage()];
        }

        if ($response->successful() && ($response->json('success') === true)) {
            Log::info('[cdn] cache Cloudflare purgé (purge_everything)', ['zone' => $zone]);

            return ['success' => true, 'status' => $response->status(), 'message' => 'purgé'];
        }

        $message = collect($response->json('errors', []))->pluck('message')->implode('; ')
            ?: 'réponse Cloudflare sans détail';

        Log::error('[cdn] purge Cloudflare échouée', [
            'zone' => $zone,
            'status' => $response->status(),
            'message' => $message,
        ]);

        return ['success' => false, 'status' => $response->status(), 'message' => $message];
    }
}
