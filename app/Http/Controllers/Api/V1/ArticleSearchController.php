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
            'q' => ['required', 'string', 'min:2'],
            'document_id' => ['nullable', 'string', 'exists:legal_documents,id'],
        ]);

        $query = $request->input('q');
        $documentId = $request->input('document_id');

        // 1. Generate Embedding
        $embedding = $this->generateEmbedding($query);
        $embeddingString = '[' . implode(',', $embedding) . ']';

        // 2. Build Query with Scoring
        // Score A: ts_rank (scaled 0-1 approx)
        // Score B: 1 - cosine_distance (similarity 0-1)
        // Final Score: (Ranking * 0.7) + (Similarity * 0.3)
        // We select articles.id to group/distinct if needed, but here we query versions and join articles
        
        $results = DB::table('article_versions as av')
            ->join('articles as a', 'av.article_id', '=', 'a.id')
            ->join('legal_documents as ld', 'a.document_id', '=', 'ld.id')
            ->join('document_types as dt', 'ld.type_code', '=', 'dt.code')
            ->leftJoin('structure_nodes as sn', 'a.parent_node_id', '=', 'sn.id')
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
            ])
            ->selectRaw("ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) as rank_score", [$query])
            ->selectRaw("COALESCE(1 - (av.embedding <=> ?::vector), 0) as similarity_score", [$embeddingString])
            ->selectRaw("(ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) * 0.7) + (COALESCE(1 - (av.embedding <=> ?::vector), 0) * 0.3) as total_score", [$query, $embeddingString])
            ->whereRaw("av.validation_status = 'validated'") // Only validated versions
            ->where(function ($q) use ($query, $embeddingString) {
                // Ensure strictly valid candidates mostly match either keyword or vector
                $q->whereRaw("av.search_tsv @@ websearch_to_tsquery('french', ?)", [$query])
                  ->orWhereRaw("av.embedding <=> ?::vector < 0.5", [$embeddingString]);
            });

        if ($documentId) {
            $results->where('a.document_id', $documentId);
        }

        $paginator = $results
            ->orderByDesc('total_score')
            ->paginate($request->integer('per_page', 20));

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
