<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureApiHeaders
{
    /**
     * Politique d'en-têtes de l'hôte API « machine » (api.mibeko.fr) :
     * en-têtes de sécurité standards + anti-indexation par défaut.
     *
     * Deux échappatoires volontaires, gérées par garde « ne pas écraser » :
     * - une route de contenu public (page de partage) peut se rendre indexable
     *   en posant elle-même `X-Robots-Tag` (ex. `all`) pour laisser le
     *   `rel=canonical` consolider le SEO vers mibeko.fr ;
     * - une réponse qui doit être encadrée (proxy PDF, via CSP `frame-ancestors`)
     *   n'est pas bloquée par un `X-Frame-Options: DENY` aveugle.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // N'impose `DENY` que si la réponse ne définit pas déjà sa politique de
        // cadrage (le proxy PDF autorise mibeko.fr via CSP `frame-ancestors`).
        $framePolicy = (string) $response->headers->get('Content-Security-Policy');
        if (! $response->headers->has('X-Frame-Options') && ! str_contains($framePolicy, 'frame-ancestors')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        // Anti-indexation par défaut, sauf si la route a déjà tranché.
        if (! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
