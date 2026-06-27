<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDocsAccess
{
    /**
     * Protège la documentation API (Scramble) par authentification HTTP Basic.
     *
     * Lorsque des identifiants sont configurés (API_DOCS_USERNAME /
     * API_DOCS_PASSWORD), ils sont exigés dans tous les environnements, ce qui
     * permet d'exposer un portail développeur en production sans l'ouvrir au
     * public. Sans identifiants, l'accès n'est ouvert qu'hors production.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $username = config('docs.username');
        $password = config('docs.password');

        if (! empty($username) && ! empty($password)) {
            $isValid = hash_equals((string) $username, (string) $request->getUser())
                && hash_equals((string) $password, (string) $request->getPassword());

            if (! $isValid) {
                return response('Authentification requise.', Response::HTTP_UNAUTHORIZED, [
                    'WWW-Authenticate' => 'Basic realm="Mibeko API — documentation"',
                    'X-Robots-Tag' => 'noindex, nofollow',
                ]);
            }

            return $next($request);
        }

        if (! app()->environment('production')) {
            return $next($request);
        }

        abort(404);
    }
}
