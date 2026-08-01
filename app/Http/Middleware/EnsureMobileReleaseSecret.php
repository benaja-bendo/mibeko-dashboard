<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie un appel machine-à-machine (la CI de mibeko-app-kmp) par secret
 * partagé, plutôt qu'un compte Sanctum : aucun humain ne se connecte, il n'y
 * a donc rien à impersonner ni de session à révoquer.
 */
class EnsureMobileReleaseSecret
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('mobile.release_webhook_secret');
        $provided = (string) $request->header('X-Mobile-Release-Secret', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Secret de release mobile invalide ou absent.');
        }

        return $next($request);
    }
}
