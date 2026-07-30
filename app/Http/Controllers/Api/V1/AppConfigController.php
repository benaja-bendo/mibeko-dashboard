<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Configuration publique de l'app mobile (mécanisme force-update).
 *
 * Endpoint anonyme appelé au démarrage de l'app KMP : il expose la fenêtre de
 * versions supportées et les liens de mise à jour (cf. config/mobile.php).
 * Le contenu est identique pour tous les appelants → cache serveur court pour
 * absorber le trafic de démarrage, plus un Cache-Control public pour laisser
 * les intermédiaires (CDN, proxy) amortir le reste.
 */
class AppConfigController extends Controller
{
    /**
     * TTL du cache serveur (~10 min) : un changement de version publié via
     * l'environnement est visible des mobiles en quelques minutes au plus.
     */
    private const CACHE_TTL_SECONDS = 600;

    public function show(): JsonResponse
    {
        $payload = Cache::remember('mobile:app-config', self::CACHE_TTL_SECONDS, function (): array {
            $iosStoreUrl = (string) config('mobile.store_urls.ios');

            return [
                'min_supported_version' => (string) config('mobile.min_supported_version'),
                'latest_version' => (string) config('mobile.latest_version'),
                'message' => config('mobile.update_message') ?: null,
                'store_urls' => [
                    'android' => (string) config('mobile.store_urls.android'),
                    'ios' => $iosStoreUrl !== '' ? $iosStoreUrl : null,
                ],
            ];
        });

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=300');
    }
}
