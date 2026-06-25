<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * @group Sitemap
 *
 * Flux compact alimentant le `sitemap.xml` du site vitrine.
 */
class SitemapController extends Controller
{
    /**
     * Liste les documents publiés et le numéro de chacun de leurs articles.
     *
     * Tout est servi en une requête (documents + articles eager-loadés, colonnes
     * minimales) et mis en cache : le site n'a pas à interroger document par
     * document pour bâtir le plan du site. Publié uniquement (`scopePublished`)
     * pour ne jamais lister de brouillon.
     */
    public function index(): JsonResponse
    {
        // On met en cache des tableaux PHP simples (pas des Collections) : selon
        // le store, sérialiser une Collection en cache la restitue mal (classe
        // incomplète au décodage JSON).
        $documents = Cache::remember('sitemap:legal', now()->addHour(), function () {
            return LegalDocument::published()
                ->with(['articles' => fn ($query) => $query
                    ->orderBy('ordre_affichage')
                    ->select('id', 'document_id', 'numero_article', 'ordre_affichage')])
                ->get(['id', 'slug', 'updated_at'])
                ->map(fn (LegalDocument $document) => [
                    'slug' => $document->slug,
                    'updated_at' => $document->updated_at?->toIso8601String(),
                    'articles' => $document->articles
                        ->pluck('numero_article')
                        ->filter()
                        ->values()
                        ->all(),
                ])
                ->all();
        });

        return $this->success($documents, 'Plan du fonds juridique');
    }
}
