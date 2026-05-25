<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentRelationController extends Controller
{
    /**
     * Get relations for an article.
     */
    public function index(string $articleId): JsonResponse
    {
        $relations = DocumentRelation::where('source_article_id', $articleId)
            ->orWhere('target_article_id', $articleId)
            ->with(['sourceDocument', 'targetDocument', 'sourceArticle', 'targetArticle'])
            ->get();

        return $this->success($relations, 'Relations récupérées avec succès');
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
        if (!($validated['source_doc_id'] || $validated['source_article_id']) || 
            !($validated['target_doc_id'] || $validated['target_article_id'])) {
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
     */
    public function searchTargets(Request $request): JsonResponse
    {
        $query = $request->query('q');
        
        if (strlen($query) < 3) {
            return $this->success([], 'Requête trop courte');
        }

        $documents = LegalDocument::where('titre_officiel', 'ilike', "%{$query}%")
            ->orWhere('reference_nor', 'ilike', "%{$query}%")
            ->limit(10)
            ->get(['id', 'titre_officiel as label'])
            ->map(fn($d) => ['id' => $d->id, 'label' => $d->label, 'type' => 'DOCUMENT']);

        $articles = Article::where('numero_article', 'ilike', "%{$query}%")
            ->with('document:id,titre_officiel')
            ->limit(10)
            ->get()
            ->map(fn($a) => [
                'id' => $a->id, 
                'label' => "Art. {$a->numero_article} - " . ($a->document->titre_officiel ?? 'Inconnu'), 
                'type' => 'ARTICLE'
            ]);

        return $this->success($documents->concat($articles), 'Cibles trouvées');
    }
}
