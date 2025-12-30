<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;

/**
 * @group Article Search
 *
 * API endpoints for searching articles.
 */
class ArticleSearchController extends Controller
{
    /**
     * Search articles.
     *
     * Full-text search across article numbers and content.
     * Returns matching articles with their parent document and node info.
     *
     * @queryParam q string required The search query. Example: licenciement
     * @queryParam type string Filter by document type code. Example: LOI
     * @queryParam page int Page number for pagination. Example: 1
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2'],
            'type' => ['nullable', 'string'],
        ]);

        $query = $request->input('q');
        $type = $request->input('type');

        $articlesQuery = Article::query()
            ->with(['activeVersion', 'parentNode', 'document.type'])
            ->whereHas('activeVersion', function ($q) use ($query) {
                $q->where('contenu_texte', 'ilike', '%'.$query.'%');
            })
            ->orWhere('numero_article', 'ilike', '%'.$query.'%');

        // Filter by document type if provided
        if ($type) {
            $articlesQuery->whereHas('document', function ($q) use ($type) {
                $q->where('type_code', $type);
            });
        }

        $articles = $articlesQuery
            ->orderBy('updated_at', 'desc')
            ->paginate(20);

        // Transform to include document context
        $results = $articles->getCollection()->map(function ($article) {
            $content = $article->activeVersion?->contenu_texte ?? '';

            return [
                'id' => $article->id,
                'number' => $article->numero_article,
                'content' => $content,
                'document_id' => $article->document_id,
                'document_title' => $article->document?->titre_officiel,
                'document_type' => $article->document?->type?->code,
                'node_title' => $article->parentNode?->titre ?? '',
                'breadcrumb' => $this->buildBreadcrumb($article),
            ];
        });

        return response()->json([
            'data' => $results,
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page' => $articles->lastPage(),
                'per_page' => $articles->perPage(),
                'total' => $articles->total(),
            ],
        ]);
    }

    /**
     * Build a breadcrumb string for an article.
     */
    private function buildBreadcrumb(Article $article): string
    {
        $parts = [];

        if ($article->document?->type) {
            $parts[] = $article->document->type->nom;
        }

        if ($article->document) {
            // Truncate long titles
            $title = $article->document->titre_officiel;
            if (strlen($title) > 40) {
                $title = substr($title, 0, 40).'...';
            }
            $parts[] = $title;
        }

        if ($article->parentNode) {
            $parts[] = $article->parentNode->titre ?? $article->parentNode->numero;
        }

        return implode(' > ', $parts);
    }
}
