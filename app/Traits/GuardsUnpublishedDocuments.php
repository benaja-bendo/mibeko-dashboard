<?php

namespace App\Traits;

use App\Models\LegalDocument;
use Illuminate\Http\Request;

/**
 * Garde d'accès au corpus non publié sur les endpoints REST publics.
 *
 * Les routes de lecture `legal-documents` (index, show, tree, download, pdf,
 * export) sont hors `auth:sanctum` : le site vitrine et l'app mobile les
 * consomment sans compte. Mais le dashboard éditeur consomme ces MÊMES
 * endpoints pour travailler sur les brouillons. Ce trait départage les deux
 * usages : un jeton Sanctum porteur du rôle editor/admin voit tout le corpus,
 * tout autre appel (anonyme, ou compte sans rôle éditorial) ne voit que les
 * documents publiés (scope {@see LegalDocument::scopePublished()}).
 *
 * Un document non publié répond 404 — jamais 403 — afin de ne pas révéler
 * son existence par énumération d'identifiants.
 */
trait GuardsUnpublishedDocuments
{
    /**
     * L'appelant peut-il voir les documents non publiés ?
     *
     * Sur une route publique, `$request->user('sanctum')` résout l'utilisateur
     * si un jeton Bearer valide est fourni, sans exiger d'authentification.
     */
    protected function canViewUnpublishedDocuments(Request $request): bool
    {
        $user = $request->user('sanctum');

        return $user !== null && $user->hasAnyRole(['editor', 'admin']);
    }

    /**
     * Répond 404 si le document n'est pas visible par l'appelant.
     */
    protected function ensureDocumentIsVisible(Request $request, LegalDocument $document): void
    {
        if ($this->canViewUnpublishedDocuments($request)) {
            return;
        }

        if (! LegalDocument::query()->published()->whereKey($document->getKey())->exists()) {
            abort(404);
        }
    }
}
