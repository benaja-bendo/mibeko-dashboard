<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ArticleResource;
use App\Models\Article;
use App\Models\LegalDocument;
use App\Contracts\AiServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @group Article Search
 *
 * API endpoints for searching articles.
 */
class ArticleSearchController extends Controller
{
    protected AiServiceInterface $aiService;

    public function __construct(AiServiceInterface $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Search articles (Hybrid: Vector + Full-Text) and provide AI answer (RAG).
     *
     * This endpoint performs a sophisticated hybrid search:
     * 1. **Full-Text Search**: Uses PostgreSQL `ts_rank` for precise keyword matching.
     * 2. **Semantic Search**: Uses `pgvector` and OpenAI embeddings to find conceptually related articles.
     * 3. **AI Answer**: If a query is provided, it uses the top results as context for a RAG (Retrieval-Augmented Generation) answer.
     *
     * @queryParam q string required The search query or question (e.g., "quels sont mes droits au travail ?").
     * @queryParam document_id string Optional. UUID of a specific document to search within.
     * @queryParam tag string Optional. Slug of a tag to filter by.
     * @queryParam type string Optional. Code of a document type (e.g., "LOI", "CODE") to filter by.
     * @queryParam per_page integer Optional. Results per page. Default: 20.
     * 
     * @response 200 {
     *  "success": true,
     *  "message": "Réponse générée avec succès",
     *  "data": {
     *    "answer": "Selon le Code du travail congolais...",
     *    "sources": [
     *      {
     *        "id": "uuid",
     *        "number": "12",
     *        "content": "Texte de l'article...",
     *        "document_title": "Code du Travail",
     *        "breadcrumb": "Code > Code du Travail > Titre I",
     *        "score": 0.85
     *      }
     *    ],
     *    "pagination": { "total": 1, "per_page": 20, "current_page": 1, "last_page": 1 }
     *  }
     * }
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
            try {
                $embedding = $this->aiService->generateEmbedding($query);
            } catch (\Exception $e) {
                return $this->error([], 'Erreur lors de la génération de l\'embedding via le service d\'IA', 500);
            }

            if (empty($embedding)) {
                return $this->error([], 'Erreur lors de la génération de l\'embedding via le service d\'IA', 500);
            }

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
                'score' => isset($item->total_score) ? round((float) $item->total_score, 4) : 0,
            ];
        });

        // 4. Generate AI Answer (RAG) if it's a direct question
        $aiAnswer = null;
        if (!empty($query)) {
            $topArticles = $paginator->items();
            $sources = array_slice($topArticles, 0, 5); // Take top 5 for context

            Log::info("AI Search Decision", [
                'query' => $query,
                'source_count' => count($sources),
                'top_score' => count($sources) > 0 ? $sources[0]['score'] : null
            ]);

            if (empty($sources)) {
                $aiAnswer = "Désolé, je ne trouve aucune information pertinente dans la base de données Mibeko concernant votre demande. Ma base de connaissances est limitée aux textes de loi officiels de la République du Congo intégrés dans l'application.";
            } else {
                $aiAnswer = $this->generateAiAnswer($query, $sources);
            }
        }

        if ($aiAnswer) {
            return $this->success([
                'answer' => $aiAnswer,
                'sources' => $paginator->items(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                ],
            ], 'Réponse générée avec succès');
        }

        return $this->paginatedSuccess($paginator, null, 'Résultats de recherche récupérés avec succès');
    }

    /**
     * Generate AI answer using AI service (RAG).
     */
    private function generateAiAnswer(string $query, array $articles): ?string
    {
        if (empty($articles)) {
            return null;
        }

        // Construction du contexte à partir des articles trouvés
        $context = "";
        foreach ($articles as $index => $article) {
            $context .= "--- SOURCE " . ($index + 1) . " ---\n";
            $context .= "Document : " . $article['document_title'] . "\n";
            $context .= "Article : " . $article['number'] . "\n";
            $context .= "Contenu : " . $article['content'] . "\n\n";
        }

        $systemPrompt = "Tu es un expert juridique spécialisé dans le droit congolais. Ton rôle est d'aider les citoyens à comprendre leurs droits de manière rigoureuse.\n\n"
                      . "RÈGLES CRITIQUES :\n"
                      . "1. Réponds UNIQUEMENT en te basant sur les extraits de loi fournis.\n"
                      . "2. N'utilise JAMAIS tes propres connaissances externes ou des informations qui ne sont pas dans les sources fournies.\n"
                      . "3. Si les extraits fournis ne permettent pas de répondre précisément à la question, indique clairement que tu ne trouves pas l'information spécifique dans la base de données Mibeko.\n"
                      . "4. Pour chaque point de ta réponse, cite le document et le numéro d'article utilisé.\n"
                      . "5. Garde un ton professionnel, neutre et pédagogique.";

        $userPrompt = "Voici les extraits de loi pertinents trouvés dans la base Mibeko :\n\n"
                    . $context
                    . "Question de l'utilisateur : " . $query . "\n\n"
                    . "Réponse (en français) :";

        return $this->aiService->generateChatCompletion([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ]);
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
}
