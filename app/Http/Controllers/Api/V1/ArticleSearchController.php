<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Article Search
 *
 * API endpoints for searching articles.
 */
class ArticleSearchController extends Controller
{
    /**
     * Search articles (Hybrid: Vector + Full-Text).
     *
     * Combines `ts_rank` (keyword search) and `cosine_distance` (semantic search).
     *
     * @queryParam q string required The search query.
     * @queryParam document_id string Optional. Filter by specific document UUID.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'min:2'],
            'document_id' => ['nullable', 'string', 'exists:legal_documents,id'],
            'tag' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'exists:document_types,code'],
        ]);

        $query = $request->input('q');
        $documentId = $request->input('document_id');
        $tag = $request->input('tag');
        $type = $request->input('type');

        if (empty($query) && empty($tag)) {
            return response()->json(['data' => [], 'meta' => []]);
        }

        // Base query with common joins
        $results = DB::table('article_versions as av')
            ->join('articles as a', 'av.article_id', '=', 'a.id')
            ->join('legal_documents as ld', 'a.document_id', '=', 'ld.id')
            ->join('document_types as dt', 'ld.type_code', '=', 'dt.code')
            ->leftJoin('structure_nodes as sn', 'a.parent_node_id', '=', 'sn.id')
            ->where('av.validation_status', 'validated')
            ->select([
                'a.id as article_id',
                'a.numero_article',
                'a.ordre_affichage',
                'av.contenu_texte',
                'av.validation_status',
                'ld.id as document_id',
                'ld.titre_officiel as document_title',
                'dt.code as document_type_code',
                'dt.nom as type_name',
                'sn.titre as node_title',
            ]);

        // Apply Tag Filter
        if ($tag) {
            $results->join('taggables as tgb', function ($join) {
                $join->on('a.id', '=', 'tgb.taggable_id')
                    ->where('tgb.taggable_type', '=', 'App\Models\Article');
            })
                ->join('tags as t', 'tgb.tag_id', '=', 't.id')
                ->where('t.slug', $tag);
        }

        // Apply Document Filter
        if ($documentId) {
            $results->where('a.document_id', $documentId);
        }

        // Apply Type Filter
        if ($type) {
            $results->where('ld.type_code', $type);
        }

        // Apply Search Logic if query is present
        if (!empty($query)) {
            // 1. Generate Embedding
            $embedding = $this->generateEmbedding($query);
            $embeddingString = '[' . implode(',', $embedding) . ']';

            // 2. Add scoring and search conditions
            // Boost matches in document title
            $results->selectRaw("ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) as rank_score", [$query])
                ->selectRaw("COALESCE(1 - (av.embedding <=> ?::vector), 0) as similarity_score", [$embeddingString])
                // New: Title match score (high priority)
                ->selectRaw("CASE WHEN ld.titre_officiel ILIKE ? THEN 1.0 ELSE 0.0 END as title_exact_match", ["%$query%"])
                // New: Article number boost
                ->selectRaw("CASE WHEN a.numero_article = ? THEN 1.0 ELSE 0.0 END as article_num_match", [$query])
                // Combined score with weights:
                // 40% Document Title match, 30% Keyword rank, 20% Semantic similarity, 10% Article number
                ->selectRaw("
                    (CASE WHEN ld.titre_officiel ILIKE ? THEN 0.4 ELSE 0.0 END) +
                    (ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) * 0.3) +
                    (COALESCE(1 - (av.embedding <=> ?::vector), 0) * 0.2) +
                    (CASE WHEN a.numero_article = ? THEN 0.1 ELSE 0.0 END)
                    as total_score
                ", ["%$query%", $query, $embeddingString, $query])
                ->where(function ($q) use ($query, $embeddingString) {
                    $q->whereRaw("av.search_tsv @@ websearch_to_tsquery('french', ?)", [$query])
                      ->orWhereRaw("av.embedding <=> ?::vector < 0.5", [$embeddingString])
                      ->orWhere('ld.titre_officiel', 'ILIKE', "%$query%")
                      ->orWhere('a.numero_article', '=', $query);
                })
                ->orderByDesc('total_score');
        } else {
            // Default ordering for browsing by tag
            $results->orderBy('a.ordre_affichage');
        }

        $paginator = $results->paginate($request->integer('per_page', 20));

        // 3. Transform for Response - Flat format matching RemoteSearchResult
        $paginator->getCollection()->transform(function ($item) {
            // Build breadcrumb: DocumentType > DocumentTitle > NodeTitle
            $breadcrumb = implode(' > ', array_filter([
                $item->type_name,
                $item->document_title,
                $item->node_title,
            ]));

            return [
                'id' => $item->article_id,
                'number' => $item->numero_article ?? '',
                'order' => $item->ordre_affichage ?? 0,
                'content' => $item->contenu_texte,
                'document_id' => $item->document_id,
                'document_title' => $item->document_title ?? '',
                'document_type' => $item->document_type_code ?? '',
                'node_title' => $item->node_title ?? '',
                'breadcrumb' => $breadcrumb,
                'validation_status' => $item->validation_status ?? 'validated',
                'score' => round((float) $item->total_score, 4),
            ];
        });

        return $this->paginatedSuccess($paginator, null, 'Résultats de recherche récupérés avec succès');
    }

    /**
     * Format query for Postgres to_tsquery
     */
    private function formatTsQuery(string $query): string
    {
        // Simple sanitization: replace spaces with & (AND) for strict search, or | (OR) for loose
        // Using 'plainto_tsquery' logic simulation or just strict AND
        $parts = array_filter(explode(' ', trim($query)));
        return implode(' & ', $parts);
    }

    /**
     * Generate embedding using OpenAI
     */
    private function generateEmbedding(string $text): array
    {
        // Mock for MVP if OpenAI key not set, or use real call
        // Using OpenAI Laravel client
        try {
            $response = \OpenAI\Laravel\Facades\OpenAI::embeddings()->create([
                'model' => 'text-embedding-3-small',
                'input' => $text,
            ]);
            return $response->embeddings[0]->embedding;
        } catch (\Exception $e) {
            // Fallback mock (all zeros) or error handling
            // For dev without API key:
            return array_fill(0, 1536, 0.0);
        }
    }
}
