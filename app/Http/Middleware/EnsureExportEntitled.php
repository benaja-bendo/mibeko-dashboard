<?php

namespace App\Http\Middleware;

use App\Services\EntitlementsResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verrouille l'export PDF (`legal-documents/{id}/export`,
 * `articles/{id}/export`) derrière l'entitlement `export` — mibeko-dashboard#86.
 *
 * Ces deux routes restent hors du groupe `auth:sanctum` (comme le reste du
 * corpus REST) car deux usages bien réels n'y portent aucun jeton Bearer : le
 * clic direct `<a href>` du lecteur Bibliothèque (mibeko-front) et l'URL
 * brute ouverte par l'app mobile en mode invité. Cette garde accepte donc
 * DEUX preuves d'entitlement, l'une ou l'autre :
 *  - un jeton Sanctum valide dont `EntitlementsResolver` résout `export` à
 *    `true` — couvre le dashboard éditeur et tout client qui porte déjà un
 *    Bearer sur cet appel (l'app mobile connectée notamment) : rien à
 *    changer côté eux ;
 *  - une signature de route valide (`hasValidSignature`), produite
 *    uniquement par `LegalDocumentExportController::mint*Token()` APRÈS
 *    avoir vérifié le même entitlement — c'est le mécanisme qui survit à un
 *    lien brut, à durée de vie courte.
 */
class EnsureExportEntitled
{
    public function __construct(private readonly EntitlementsResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user !== null) {
            abort_unless(
                $this->resolver->resolve($user)['features']['export'],
                403,
                "L'export PDF Mibeko est réservé aux comptes Pro."
            );

            return $next($request);
        }

        abort_unless($request->hasValidSignature(), 403, "L'export PDF Mibeko est réservé aux comptes Pro.");

        return $next($request);
    }
}
