<?php

namespace App\Traits;

use App\Http\Controllers\Api\V1\DocumentCurationController;
use App\Models\LegalDocument;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Garde d'accès des outils MCP de curation : ils exposent du contenu non
 * publié et déclenchent des analyses, donc réservés aux utilisateurs ayant la
 * permission de mise à jour des documents (éditeurs/admins), comme la vue
 * Contrôle ({@see DocumentCurationController}).
 *
 * Le transport web (`Mcp::web`) est derrière `auth:sanctum` : un utilisateur
 * est toujours présent et la Policy s'applique. Le transport local
 * (`Mcp::local`, stdio) n'a pas d'utilisateur : il suppose déjà un accès shell
 * à la machine (même niveau de confiance qu'`artisan tinker`), on ne bloque pas.
 */
trait AuthorizesMcpCuration
{
    /**
     * Renvoie la réponse d'erreur MCP si l'utilisateur authentifié n'a pas la
     * permission de curation, null sinon (autorisé, ou transport local sans user).
     *
     * À appeler AVANT toute lecture en base : répondre différemment selon
     * l'existence d'un UUID servirait d'oracle d'existence aux non-éditeurs
     * (règle maison : un contenu non publié ne révèle jamais son existence,
     * cf. GuardsUnpublishedDocuments). La policy `update` n'utilise pas
     * l'instance du document, un modèle vierge suffit au contrôle.
     */
    private function denyUnlessCurator(Request $request): ?Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null && $user->cannot('update', new LegalDocument)) {
            return Response::error(
                'Accès refusé : outil réservé aux éditeurs (permission documents.update).'
            );
        }

        return null;
    }
}
