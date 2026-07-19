<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Traits\GuardsUnpublishedDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentRelationController extends Controller
{
    use GuardsUnpublishedDocuments;

    /**
     * Get relations for an article.
     *
     * Ces routes de lecture sont hors `auth:sanctum` (le dashboard éditeur les
     * consomme avec un jeton Bearer, mais elles sont techniquement publiques) :
     * on applique la même garde anti-fuite que le corpus. Pour un appelant non
     * privilégié, l'article d'un document non publié répond 404 (anti-oracle),
     * et les relations pointant vers un document non publié sont retirées de la
     * réponse — sinon la recherche de brouillons fuirait par ce biais.
     */
    public function index(Request $request, string $articleId): JsonResponse
    {
        $canViewUnpublished = $this->canViewUnpublishedDocuments($request);

        $article = Article::query()
            ->with('document')
            ->findOrFail($articleId);

        // Anti-oracle : un brouillon parent n'existe pas pour le grand public.
        if (! $canViewUnpublished && ! $this->documentIsPublished($article->document)) {
            abort(404);
        }

        $relations = DocumentRelation::query()
            ->where(fn ($query) => $query
                ->where('source_article_id', $articleId)
                ->orWhere('target_article_id', $articleId))
            ->with(['sourceDocument', 'targetDocument', 'sourceArticle', 'targetArticle'])
            ->get();

        // Ne jamais révéler un texte non publié via « l'autre bout » d'une
        // relation : on écarte toute relation dont un document rattaché n'est
        // pas publié (les éditeurs/admins gardent la vue complète).
        if (! $canViewUnpublished) {
            $relations = $relations->filter(function (DocumentRelation $relation): bool {
                return $this->documentIsPublished($relation->sourceDocument)
                    && $this->documentIsPublished($relation->targetDocument);
            })->values();
        }

        return $this->success($relations, 'Relations récupérées avec succès');
    }

    /**
     * Un document rattaché à une relation est-il visible du grand public ?
     *
     * `null` (relation au niveau article sans document explicite) est traité
     * comme neutre/visible : la garde porte sur les documents effectivement
     * référencés.
     */
    private function documentIsPublished(?LegalDocument $document): bool
    {
        if ($document === null) {
            return true;
        }

        return $document->curation_status === LegalDocument::STATUS_PUBLISHED;
    }

    /**
     * Store a new relation.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_doc_id' => 'nullable|exists:legal_documents,id',
            'target_doc_id' => 'nullable|exists:legal_documents,id',
            'source_article_id' => 'nullable|exists:articles,id',
            'target_article_id' => 'nullable|exists:articles,id',
            'relation_type' => 'required|string|in:CREE,MODIFIE,ABROGE,CITE,COMPLETE,RENUMEROTE',
            'commentaire' => 'nullable|string',
            'effective_date' => 'nullable|date',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'meta' => 'nullable|array',
        ]);

        // Validation: at least one source and one target (doc or article)
        if (! ($validated['source_doc_id'] || $validated['source_article_id']) ||
            ! ($validated['target_doc_id'] || $validated['target_article_id'])) {
            return $this->error(null, 'Une source et une cible sont nécessaires.', 422);
        }

        $relation = DocumentRelation::create($validated);

        return $this->success($relation, 'Relation créée avec succès', 201);
    }

    /**
     * Delete a relation.
     */
    public function destroy(string $id): JsonResponse
    {
        $relation = DocumentRelation::findOrFail($id);
        $relation->delete();

        return $this->success(null, 'Relation supprimée avec succès');
    }

    /**
     * Search for potential targets (articles or documents).
     *
     * Route de recherche hors `auth:sanctum` : un appelant non privilégié ne
     * doit matcher que le corpus publié (documents et articles rattachés à un
     * document publié), sinon la simple frappe d'un titre révélerait l'existence
     * d'un brouillon. Les éditeurs/admins (jeton Bearer) cherchent tout le fonds.
     */
    public function searchTargets(Request $request): JsonResponse
    {
        $query = (string) $request->query('q', '');

        if (strlen($query) < 3) {
            return $this->success([], 'Requête trop courte');
        }

        $canViewUnpublished = $this->canViewUnpublishedDocuments($request);

        $documents = LegalDocument::query()
            ->when(! $canViewUnpublished, fn ($q) => $q->published())
            ->where(fn ($q) => $q
                ->where('titre_officiel', 'ilike', "%{$query}%")
                ->orWhere('reference_nor', 'ilike', "%{$query}%"))
            ->limit(10)
            ->get(['id', 'titre_officiel as label'])
            ->map(fn ($d) => ['id' => $d->id, 'label' => $d->label, 'type' => 'DOCUMENT']);

        $articles = Article::query()
            ->when(! $canViewUnpublished, fn ($q) => $q->whereHas(
                'document',
                fn ($docQuery) => $docQuery->published()
            ))
            ->where('numero_article', 'ilike', "%{$query}%")
            ->with('document:id,titre_officiel')
            ->limit(10)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'label' => "Art. {$a->numero_article} - ".($a->document->titre_officiel ?? 'Inconnu'),
                'type' => 'ARTICLE',
            ]);

        return $this->success($documents->concat($articles), 'Cibles trouvées');
    }
}
