<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\SearchesArticles;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Laravel\Ai\agent;

/**
 * @group Article Search
 *
 * API endpoints for searching articles.
 */
class ArticleSearchController extends Controller
{
    use SearchesArticles;

    /**
     * Get a single validated article with its document context.
     *
     * Permet à l'app mobile d'ouvrir un résultat de recherche dont le document
     * n'est pas encore en cache local : elle résout ici le document parent,
     * puis télécharge sa structure complète pour la lecture hors-ligne.
     *
     * @urlParam id string required The article UUID.
     */
    public function context(string $id): JsonResponse
    {
        $article = $this->fetchArticleContext($id);

        if ($article === null) {
            return $this->error(null, 'Article introuvable.', 404);
        }

        return $this->success($article, 'Article récupéré avec succès');
    }

    /**
     * Search articles (Hybrid: Vector + Full-Text) and provide AI answer (RAG).
     *
     * This endpoint performs a sophisticated hybrid search:
     * 1. **Full-Text Search**: Uses PostgreSQL `ts_rank` for precise keyword matching.
     * 2. **Semantic Search**: Uses `pgvector` and Mistral embeddings to find conceptually related articles.
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
    /**
     * Applique le score de pertinence et la clause de sélection à une requête.
     *
     * Extraite de `search()` le 25/08/2026 (mibeko-dashboard#50) pour que la
     * recherche puisse s'exécuter en deux passages : une première fois sans
     * rappel sémantique, une seconde avec s'il a fallu le rallumer. Les deux
     * jeux de poids sont ceux d'avant, inchangés — seul leur moment d'emploi
     * change.
     *
     * @param  Builder  $results
     */
    private function appliquerPertinence($results, string $query, ?string $embeddingString, string $sort): void
    {
        $orTsQuery = $this->formatTsQuery($query);
        $articleNum = $query;
        if (preg_match('/article\s+(\d+)/i', $query, $matches)) {
            $articleNum = $matches[1];
        }

        $results->selectRaw("ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) as rank_score", [$query])
            ->selectRaw('CASE WHEN ld.titre_officiel ILIKE ? THEN 1.0 ELSE 0.0 END as title_exact_match', ["%$query%"])
            ->selectRaw('CASE WHEN a.numero_article = ? THEN 1.0 ELSE 0.0 END as article_num_match', [$articleNum]);

        if ($embeddingString !== null) {
            // Filet sémantique borné aux `semanticTopK` plus proches voisins
            // (SearchesArticles::semanticCandidateIds), jamais un seuil de
            // distance non borné : le seuil fixe précédent (< 0.5, alors que la
            // distance maximale mesurée sur tout le corpus est ~0.30) faisait
            // remonter la quasi-totalité des articles embeddés pour n'importe
            // quelle requête. Cloné avant les selectRaw ci-dessous : ceux-ci ne
            // comptent pas, semanticCandidateIds réinitialise le SELECT du clone.
            $semanticIds = $this->semanticCandidateIds($results, $embeddingString);

            $results->selectRaw('COALESCE(1 - (av.embedding <=> ?::vector), 0) as similarity_score', [$embeddingString])
                ->selectRaw("
                        (CASE WHEN ld.titre_officiel ILIKE ? THEN 0.4 ELSE 0.0 END) +
                        (ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) * 0.3) +
                        (CASE WHEN ? != '' THEN ts_rank(av.search_tsv, to_tsquery('french', ?)) * 0.1 ELSE 0.0 END) +
                        (COALESCE(1 - (av.embedding <=> ?::vector), 0) * 0.2) +
                        (CASE WHEN a.numero_article = ? THEN 0.2 ELSE 0.0 END)
                        as total_score
                    ", ["%$query%", $query, $orTsQuery, $orTsQuery, $embeddingString, $articleNum])
                ->where(function ($q) use ($query, $orTsQuery, $articleNum, $semanticIds) {
                    $q->whereRaw("av.search_tsv @@ websearch_to_tsquery('french', ?)", [$query]);
                    if ($orTsQuery !== '') {
                        $q->orWhereRaw("av.search_tsv @@ to_tsquery('french', ?)", [$orTsQuery]);
                    }
                    if (! empty($semanticIds)) {
                        $q->orWhereIn('a.id', $semanticIds);
                    }
                    $q->orWhere('ld.titre_officiel', 'ILIKE', "%$query%")
                        ->orWhere('a.numero_article', '=', $articleNum);
                });
        } else {
            $results->selectRaw('0 as similarity_score')
                ->selectRaw("
                        (CASE WHEN ld.titre_officiel ILIKE ? THEN 0.5 ELSE 0.0 END) +
                        (ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) * 0.4) +
                        (CASE WHEN ? != '' THEN ts_rank(av.search_tsv, to_tsquery('french', ?)) * 0.2 ELSE 0.0 END) +
                        (CASE WHEN a.numero_article = ? THEN 0.2 ELSE 0.0 END)
                        as total_score
                    ", ["%$query%", $query, $orTsQuery, $orTsQuery, $articleNum])
                ->where(function ($q) use ($query, $orTsQuery, $articleNum) {
                    $q->whereRaw("av.search_tsv @@ websearch_to_tsquery('french', ?)", [$query]);
                    if ($orTsQuery !== '') {
                        $q->orWhereRaw("av.search_tsv @@ to_tsquery('french', ?)", [$orTsQuery]);
                    }
                    $q->orWhere('ld.titre_officiel', 'ILIKE', "%$query%")
                        ->orWhere('a.numero_article', '=', $articleNum);
                });
        }

        if ($sort === 'date_desc') {
            $results->orderByDesc('ld.date_publication');
        } elseif ($sort === 'date_asc') {
            $results->orderBy('ld.date_publication');
        } else {
            $results->orderByDesc('total_score');
        }
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'min:2'],
            'document_id' => ['nullable', 'string', 'exists:legal_documents,id'],
            'tag' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'exists:document_types,code'],
            'institution_id' => ['nullable', 'string', 'exists:institutions,id'],
            'legal_scope' => ['nullable', 'string', 'in:national,ohada,communautaire'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string', 'in:relevance,date_desc,date_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $request->input('q');
        $documentId = $request->input('document_id');
        $tag = $request->input('tag');
        $type = $request->input('type');
        $institutionId = $request->input('institution_id');
        $legalScope = $request->input('legal_scope');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $sort = $request->input('sort', 'relevance');

        if (empty($query) && empty($tag)) {
            return response()->json(['data' => [], 'meta' => []]);
        }

        $results = DB::table('article_versions as av')
            ->join('articles as a', 'av.article_id', '=', 'a.id')
            ->join('legal_documents as ld', 'a.document_id', '=', 'ld.id')
            ->join('document_types as dt', 'ld.type_code', '=', 'dt.code')
            ->leftJoin('structure_nodes as sn', 'a.parent_node_id', '=', 'sn.id')
            ->leftJoin('institutions as i', 'ld.institution_id', '=', 'i.id')
            // Même garde que SearchesArticles::baseArticleQuery : la publication
            // du document fait foi, seuls les articles marqués `error` sont exclus.
            ->where('av.validation_status', '!=', 'error')
            // Seule la version active est citable — une version fermée par une
            // correction de contenu ne doit plus remonter dans les résultats.
            ->whereRaw('upper_inf(av.validity_period)')
            ->whereNull('a.deleted_at')
            ->whereNull('ld.deleted_at')
            ->whereNull('sn.deleted_at')
            ->where('ld.curation_status', 'published')
            ->select([
                'a.id as article_id',
                'a.numero_article',
                'a.ordre_affichage',
                'av.contenu_texte',
                'av.validation_status',
                'ld.id as document_id',
                'ld.titre_officiel as document_title',
                'ld.legal_scope',
                'ld.date_publication',
                'ld.institution_id',
                'dt.code as document_type_code',
                'dt.nom as type_name',
                'sn.titre as node_title',
                'i.nom as institution_name',
            ]);

        // ── Filtres serveur (périmètre, institution, dates) ──────────────────
        if ($institutionId) {
            $results->where('ld.institution_id', $institutionId);
        }
        if ($legalScope) {
            $results->where('ld.legal_scope', $legalScope);
        }
        if ($dateFrom) {
            $results->whereDate('ld.date_publication', '>=', $dateFrom);
        }
        if ($dateTo) {
            $results->whereDate('ld.date_publication', '<=', $dateTo);
        }

        if ($tag) {
            $results->join('taggables as tgb', function ($join) {
                $join->on('a.id', '=', 'tgb.taggable_id')
                    ->where('tgb.taggable_type', '=', 'App\Models\Article');
            })
                ->join('tags as t', 'tgb.tag_id', '=', 't.id')
                ->where('t.slug', $tag);
        }

        if ($documentId) {
            $results->where('a.document_id', $documentId);
        }

        if ($type) {
            $results->where('ld.type_code', $type);
        }

        $perPage = $request->integer('per_page', 20);

        if (! empty($query)) {
            // Les filtres sont posés, le scoring ne l'est pas encore : ce clone
            // sert de point de reprise si le second passage devient nécessaire.
            $sansScoring = clone $results;

            // Premier passage : lexical seul. Générer l'embedding coûte un appel
            // réseau que payait jusqu'ici CHAQUE recherche, y compris celles que
            // le plein-texte satisfait déjà — mesuré sur api.mibeko.fr le
            // 25/08/2026, c'est ce qui faisait osciller cette route entre 0,6 s
            // (embedding en cache) et 3,7 s (requête neuve).
            $this->appliquerPertinence($results, $query, null, $sort);
            $paginator = $results->paginate($perPage);

            // Le lexical a échoué : c'est le cas pour lequel le rappel
            // conceptuel existe (« héritage » ↔ « succession »). On paie
            // l'embedding là, et là seulement.
            if ($paginator->total() < $this->rappelSuffisant && strlen($query) > 2) {
                $embeddingString = null;
                try {
                    $embedding = Str::of($query)->toEmbeddings(cache: true);
                    if (! empty($embedding)) {
                        $embeddingString = '['.implode(',', $embedding).']';
                    }
                } catch (\Exception $e) {
                    Log::warning('Erreur lors de la génération de l\'embedding (fallback sur la recherche textuelle): '.$e->getMessage());
                }

                if ($embeddingString !== null) {
                    $avecSemantique = $sansScoring;
                    $this->appliquerPertinence($avecSemantique, $query, $embeddingString, $sort);
                    $paginator = $avecSemantique->paginate($perPage);
                }
            }
        } else {
            $results->orderBy('a.ordre_affichage');
            $paginator = $results->paginate($perPage);
        }

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
                'legal_scope' => $item->legal_scope ?? 'national',
                'institution_id' => $item->institution_id,
                'institution' => $item->institution_name,
                'date_publication' => $item->date_publication,
                'validation_status' => $item->validation_status ?? 'validated',
                'score' => isset($item->total_score) ? round((float) $item->total_score, 4) : 0,
            ];
        });

        $aiAnswer = null;
        $wantsRag = $request->boolean('rag', false);
        $isAutocomplete = $request->boolean('autocomplete', false);

        // mibeko-dashboard#14 — le déclenchement n'est plus qu'explicite. Avant
        // le 09/08/2026, un simple nombre de mots ou un point d'interrogation
        // suffisait à déclencher un appel LLM complet, sans que l'appelant en
        // ait exprimé le besoin : c'était le seul poste de coût du projet sans
        // plafond dédié, exposé anonymement. Vérifié avant ce correctif :
        // aucun appelant du monorepo (mobile, front, site) n'envoie jamais
        // `rag=` — l'app mobile capture même la réponse (`aiAnswer` dans
        // `LocalLegalRepository`) sans jamais l'afficher. Rendre le flag
        // exclusif ne retire donc aucune fonctionnalité visible aujourd'hui.
        $shouldRunRag = ! $isAutocomplete && $wantsRag;

        // Le RAG explicitement demandé passe par le même plafond que les
        // autres surfaces IA (`throttle:ai_assistant` — anonyme 5/min par IP,
        // authentifié par quota de rôle). Appliqué en middleware de route ça
        // freinerait aussi la recherche lexicale, qui n'a jamais besoin d'un
        // LLM ; appliqué ici, seul l'appel effectivement coûteux est compté.
        // Un dépassement dégrade en silence vers les résultats de recherche
        // seuls (200, comme si `rag` n'avait pas été demandé) plutôt que de
        // faire échouer toute la recherche : le RAG est un bonus sur cette
        // route, jamais son seul objet.
        if (! empty($query) && $shouldRunRag && $this->ragAllowedByThrottle($request)) {
            $topArticles = $paginator->items();
            $sources = array_slice($topArticles, 0, 5); // Take top 5 for context

            Log::info('AI Search Decision', [
                'query' => $query,
                'source_count' => count($sources),
                'top_score' => count($sources) > 0 ? $sources[0]['score'] : null,
                'authenticated' => $request->user() !== null,
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
     * Vérifie et consomme le plafond du limiteur nommé `ai_assistant`
     * (`AppServiceProvider`) pour la requête courante, sans dupliquer sa
     * politique de quota ici — anonyme et authentifié restent définis à un
     * seul endroit. Passe par la vraie classe `ThrottleRequests` plutôt que
     * de ré-implémenter la résolution du limiteur nommé : celle-ci hache la
     * clé de cache avec le nom du limiteur (`md5($limiterName.$limit->key)`),
     * un détail qu'une réimplémentation manuelle risquerait de rater et qui
     * ferait retomber cette route sur un compteur différent des autres
     * surfaces IA au lieu de partager le même quota par utilisateur/IP.
     *
     * Ne renvoie jamais de réponse 429 : au dépassement, l'appelant doit
     * dégrader en silence vers la recherche seule (cf. `search()`), jamais
     * faire échouer toute la requête pour un bonus qu'elle n'a même pas
     * demandé de façon systématique.
     */
    private function ragAllowedByThrottle(Request $request): bool
    {
        try {
            app(ThrottleRequests::class)->handle(
                $request,
                fn () => response()->noContent(),
                'ai_assistant'
            );

            return true;
        } catch (ThrottleRequestsException) {
            // Limite atteinte sans `response()` propre sur le Limit — ne se
            // produit pas avec le limiteur `ai_assistant` actuel (chaque
            // branche en définit un), gardé pour ne pas dépendre de ce détail
            // si le limiteur évolue.
            return false;
        } catch (HttpResponseException) {
            // Cas réel avec `ai_assistant` : chaque branche (anonyme, admin,
            // standard/user_pro) définit `->response(...)`, donc `buildException`
            // lève systématiquement celle-ci plutôt que `ThrottleRequestsException`.
            return false;
        }
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
        $context = '';
        foreach ($articles as $index => $article) {
            // Tronquer le contenu pour éviter d'exploser la limite de tokens (contexte de l'IA)
            // L'erreur "Abnormally stopped" vient souvent d'un prompt trop long.
            $content = Str::limit($article['content'] ?? '', 2500);

            $context .= '--- SOURCE '.($index + 1)." ---\n";
            $context .= 'Document : '.$article['document_title']."\n";
            $context .= 'Article : '.$article['number']."\n";
            $context .= 'Contenu : '.$content."\n\n";
        }

        $systemPrompt = "Tu es un expert juridique spécialisé EXCLUSIVEMENT dans le droit de la République du Congo (Congo-Brazzaville). Ton rôle est d'aider les citoyens à comprendre leurs droits de manière rigoureuse.\n\n"
                      ."RÈGLES CRITIQUES :\n"
                      ."1. Tu ne dois JAMAIS faire référence à la loi française ou à la loi d'un autre pays. Tout ton contexte juridique est celui de la République du Congo.\n"
                      ."2. Réponds UNIQUEMENT en te basant sur les extraits de loi fournis.\n"
                      ."3. N'utilise JAMAIS tes propres connaissances externes ou des informations qui ne sont pas dans les sources fournies.\n"
                      ."4. Si les extraits fournis ne permettent pas de répondre précisément à la question, indique clairement que tu ne trouves pas l'information spécifique dans la base de données Mibeko.\n"
                      ."5. Pour chaque point de ta réponse, cite le document et le numéro d'article utilisé.\n"
                      .'6. Garde un ton professionnel, neutre et pédagogique.';

        $userPrompt = "Voici les extraits de loi pertinents trouvés dans la base Mibeko :\n\n"
                    .$context
                    ."Question de l'utilisateur : ".$query."\n\n"
                    .'Réponse (en français) :';

        try {
            $response = agent(instructions: $systemPrompt)->prompt($userPrompt);

            return $response->text;
        } catch (\Exception $e) {
            Log::error('Erreur lors de la génération de la réponse IA: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Format query for Postgres to_tsquery
     */
    private function formatTsQuery(string $query): string
    {
        // Nettoyage de la ponctuation et caractères spéciaux
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query);
        $parts = array_filter(explode(' ', trim($clean)), function ($w) {
            // Ignorer les petits mots (le, la, de, un, est, quoi...)
            return mb_strlen($w) > 3;
        });

        if (empty($parts)) {
            return '';
        }

        return implode(' | ', $parts);
    }
}
