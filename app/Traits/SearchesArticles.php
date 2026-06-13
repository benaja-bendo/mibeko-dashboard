<?php

namespace App\Traits;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait SearchesArticles
{
    /**
     * Search articles (Hybrid: Vector + Full-Text).
     *
     * @param  array<int, string>|null  $documentIds  Restreint la recherche à ces documents (références épinglées).
     */
    protected function searchArticles(string $query, int $limit = 5, ?string $documentType = null, ?string $documentTitle = null, ?array $documentIds = null): array
    {
        if (empty($query)) {
            return [];
        }

        $results = DB::table('article_versions as av')
            ->join('articles as a', 'av.article_id', '=', 'a.id')
            ->join('legal_documents as ld', 'a.document_id', '=', 'ld.id')
            ->join('document_types as dt', 'ld.type_code', '=', 'dt.code')
            ->leftJoin('structure_nodes as sn', 'a.parent_node_id', '=', 'sn.id')
            ->where('av.validation_status', 'validated')
            ->whereNull('a.deleted_at')
            ->whereNull('ld.deleted_at')
            ->where('ld.curation_status', 'published')
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

        // Application des filtres optionnels pour améliorer la précision
        if (! empty($documentType)) {
            $results->where('dt.code', 'ILIKE', "%$documentType%");
        }
        if (! empty($documentTitle)) {
            $results->where('ld.titre_officiel', 'ILIKE', "%$documentTitle%");
        }
        if (! empty($documentIds)) {
            $results->whereIn('ld.id', $documentIds);
        }

        $embeddingString = null;
        try {
            if (strlen($query) > 2) {
                $embedding = Str::of($query)->toEmbeddings();
                if (! empty($embedding)) {
                    $embeddingString = '['.implode(',', $embedding).']';
                }
            }
        } catch (\Exception $e) {
            Log::warning('Erreur lors de la génération de l\'embedding: '.$e->getMessage());
        }

        $orTsQuery = $this->formatTsQuery($query);
        $articleNum = $query;
        if (preg_match('/article\s+(\d+)/i', $query, $matches)) {
            $articleNum = $matches[1];
        }

        $results->selectRaw("ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) as rank_score", [$query])
            ->selectRaw('CASE WHEN ld.titre_officiel ILIKE ? THEN 1.0 ELSE 0.0 END as title_exact_match', ["%$query%"])
            ->selectRaw('CASE WHEN a.numero_article = ? THEN 1.0 ELSE 0.0 END as article_num_match', [$articleNum]);

        if ($embeddingString) {
            $results->selectRaw('COALESCE(1 - (av.embedding <=> ?::vector), 0) as similarity_score', [$embeddingString])
                ->selectRaw("
                    (CASE WHEN ld.titre_officiel ILIKE ? THEN 0.4 ELSE 0.0 END) +
                    (ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) * 0.3) +
                    (CASE WHEN ? != '' THEN ts_rank(av.search_tsv, to_tsquery('french', ?)) * 0.1 ELSE 0.0 END) +
                    (COALESCE(1 - (av.embedding <=> ?::vector), 0) * 0.2) +
                    (CASE WHEN a.numero_article = ? THEN 0.2 ELSE 0.0 END)
                    as total_score
                ", ["%$query%", $query, $orTsQuery, $orTsQuery, $embeddingString, $articleNum])
                ->where(function ($q) use ($query, $embeddingString, $orTsQuery, $articleNum) {
                    $q->whereRaw("av.search_tsv @@ websearch_to_tsquery('french', ?)", [$query]);
                    if ($orTsQuery !== '') {
                        $q->orWhereRaw("av.search_tsv @@ to_tsquery('french', ?)", [$orTsQuery]);
                    }
                    $q->orWhereRaw('av.embedding <=> ?::vector < 0.5', [$embeddingString])
                        ->orWhere('ld.titre_officiel', 'ILIKE', "%$query%")
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

        $results->orderByDesc('total_score');
        $items = $results->take($limit)->get();

        return $items->map(function ($item) {
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
        })->toArray();
    }

    /**
     * Base query for validated, published articles with their full metadata.
     *
     * Shared by the lexical library search, the on-demand AI synthesis context
     * and single-article lookups. Carries no scoring so callers add their own
     * ranking, filtering and pagination.
     */
    protected function baseArticleQuery(): Builder
    {
        return DB::table('article_versions as av')
            ->join('articles as a', 'av.article_id', '=', 'a.id')
            ->join('legal_documents as ld', 'a.document_id', '=', 'ld.id')
            ->join('document_types as dt', 'ld.type_code', '=', 'dt.code')
            ->leftJoin('structure_nodes as sn', 'a.parent_node_id', '=', 'sn.id')
            ->leftJoin('institutions as i', 'ld.institution_id', '=', 'i.id')
            ->leftJoin('official_journals as oj', 'ld.official_journal_id', '=', 'oj.id')
            ->where('av.validation_status', 'validated')
            ->whereNull('a.deleted_at')
            ->whereNull('ld.deleted_at')
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
                'ld.official_journal_id',
                'dt.code as document_type_code',
                'dt.nom as type_name',
                'sn.titre as node_title',
                'i.nom as institution_name',
                'oj.title as official_journal_title',
                'oj.publication_date as official_journal_date',
            ]);
    }

    /**
     * Apply the optional server-side filters to an article query.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyArticleFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['type'])) {
            $query->where('ld.type_code', $filters['type']);
        }
        if (! empty($filters['institution_id'])) {
            $query->where('ld.institution_id', $filters['institution_id']);
        }
        if (! empty($filters['legal_scope'])) {
            $query->where('ld.legal_scope', $filters['legal_scope']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('ld.date_publication', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('ld.date_publication', '<=', $filters['date_to']);
        }
        if (! empty($filters['document_id'])) {
            $query->where('a.document_id', $filters['document_id']);
        }
        if (! empty($filters['official_journal_id'])) {
            $query->where('ld.official_journal_id', $filters['official_journal_id']);
        }
        if (! empty($filters['tag'])) {
            $query->join('taggables as tgb', function ($join) {
                $join->on('a.id', '=', 'tgb.taggable_id')
                    ->where('tgb.taggable_type', '=', 'App\Models\Article');
            })
                ->join('tags as t', 'tgb.tag_id', '=', 't.id')
                ->where('t.slug', $filters['tag']);
        }
    }

    /**
     * Apply pure PostgreSQL full-text ranking (no embedding) to an article query.
     *
     * Score blends weighted ts_rank, exact title match and article-number match.
     * The companion WHERE keeps only rows that actually match the query.
     */
    protected function applyLexicalScoring(Builder $query, string $search): void
    {
        // Sépare une éventuelle référence « article N » du reste thématique.
        // Le mot « article » est structurel (présent dans presque tous les
        // textes) : le garder dans la recherche full-text noierait les vrais
        // résultats. On le retire du texte interrogé et on cible le numéro.
        [$articleNum, $topical] = $this->parseArticleQuery($search);

        $orTsQuery = $this->formatTsQuery($topical);
        $hasText = $topical !== '';

        $query->selectRaw("
            (CASE WHEN ?::text IS NOT NULL AND a.numero_article = ?::text THEN 2.0 ELSE 0.0 END) +
            (CASE WHEN ? != '' AND ld.titre_officiel ILIKE ? THEN 0.5 ELSE 0.0 END) +
            (CASE WHEN ? != '' THEN ts_rank(av.search_tsv, websearch_to_tsquery('french', ?)) * 0.4 ELSE 0.0 END) +
            (CASE WHEN ? != '' THEN ts_rank(av.search_tsv, to_tsquery('french', ?)) * 0.2 ELSE 0.0 END)
            as total_score
        ", [
            $articleNum, $articleNum,
            $topical, "%$topical%",
            $topical, $topical,
            $orTsQuery, $orTsQuery,
        ])
            ->where(function ($q) use ($articleNum, $topical, $orTsQuery, $hasText) {
                if ($hasText) {
                    $q->whereRaw("av.search_tsv @@ websearch_to_tsquery('french', ?)", [$topical]);
                    if ($orTsQuery !== '') {
                        $q->orWhereRaw("av.search_tsv @@ to_tsquery('french', ?)", [$orTsQuery]);
                    }
                    $q->orWhere('ld.titre_officiel', 'ILIKE', "%$topical%");
                    if ($articleNum !== null) {
                        $q->orWhere('a.numero_article', '=', $articleNum);
                    }
                } elseif ($articleNum !== null) {
                    // Requête purement « article N » : on ne renvoie que les
                    // articles portant ce numéro, sans le bruit du full-text.
                    $q->where('a.numero_article', '=', $articleNum);
                }
            });
    }

    /**
     * Sépare une requête en (numéro d'article ciblé, reste thématique).
     *
     * Reconnaît « article 45 », « art. 45 bis », « 45 code du travail » ou un
     * simple « 45 ». Le numéro est extrait du texte pour que le mot structurel
     * « article » ne pollue pas la recherche full-text.
     *
     * @return array{0: ?string, 1: string} [numéro|null, texte thématique]
     */
    protected function parseArticleQuery(string $search): array
    {
        $search = trim($search);

        if (preg_match('/\bart(?:icle)?s?\.?\s*(\d+[\w.-]*)/iu', $search, $matches)) {
            $rest = trim(preg_replace('/\bart(?:icle)?s?\.?\s*\d+[\w.-]*/iu', ' ', $search) ?? '');

            return [$matches[1], $rest];
        }

        if (preg_match('/^(\d+[\w.-]*)\s*(.*)$/u', $search, $matches)) {
            return [$matches[1], trim($matches[2])];
        }

        return [null, $search];
    }

    /**
     * Map a raw article row to the API item shape used by the library.
     *
     * @return array<string, mixed>
     */
    protected function mapArticleRow(object $item): array
    {
        $breadcrumb = implode(' > ', array_filter([
            $item->type_name ?? null,
            $item->document_title ?? null,
            $item->node_title ?? null,
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
            'institution_id' => $item->institution_id ?? null,
            'institution' => $item->institution_name ?? null,
            'date_publication' => $item->date_publication ?? null,
            // Journal Officiel d'origine : permet au client de proposer un
            // retour vers le JO ayant publié ce texte (null si non rattaché).
            'official_journal_id' => $item->official_journal_id ?? null,
            'official_journal' => isset($item->official_journal_id) ? [
                'id' => $item->official_journal_id,
                'title' => $item->official_journal_title ?? null,
                'publication_date' => $item->official_journal_date ?? null,
            ] : null,
            'validation_status' => $item->validation_status ?? 'validated',
            'score' => isset($item->total_score) ? round((float) $item->total_score, 4) : 0,
        ];
    }

    /**
     * Run a pure full-text library search and return the paginated, mapped rows.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function lexicalArticleSearch(string $search, array $filters = [], string $sort = 'relevance', int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->baseArticleQuery();
        $this->applyArticleFilters($query, $filters);
        $this->applyLexicalScoring($query, $search);

        if ($sort === 'date_desc') {
            $query->orderByDesc('ld.date_publication');
        } elseif ($sort === 'date_asc') {
            $query->orderBy('ld.date_publication');
        } else {
            $query->orderByDesc('total_score');
        }

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn ($item) => $this->mapArticleRow($item));

        return $paginator;
    }

    /**
     * Fetch the top-K lexical matches as plain arrays for the AI synthesis context.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    protected function lexicalArticleContext(string $search, array $filters = [], int $limit = 5): array
    {
        $query = $this->baseArticleQuery();
        $this->applyArticleFilters($query, $filters);
        $this->applyLexicalScoring($query, $search);

        return $query->orderByDesc('total_score')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => $this->mapArticleRow($item))
            ->all();
    }

    /**
     * Fetch a single validated article with its full context, or null if absent.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchArticleContext(string $articleId): ?array
    {
        $item = $this->baseArticleQuery()->where('a.id', $articleId)->first();

        return $item ? $this->mapArticleRow($item) : null;
    }

    /**
     * Mots structurels écartés du full-text : présents dans presque tous les
     * textes juridiques, ils ne discriminent rien et noient la pertinence.
     */
    protected array $structuralStopWords = [
        'article', 'articles', 'alinea', 'alineas', 'paragraphe', 'paragraphes',
    ];

    /**
     * Format query for Postgres to_tsquery
     */
    protected function formatTsQuery(string $query): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query);
        $parts = array_filter(explode(' ', trim($clean)), function ($w) {
            return mb_strlen($w) > 3
                && ! in_array(mb_strtolower($this->stripDiacritics($w)), $this->structuralStopWords, true);
        });

        if (empty($parts)) {
            return '';
        }

        return implode(' | ', $parts);
    }

    /**
     * Retire les accents pour comparer un mot à la liste des stop-words.
     */
    protected function stripDiacritics(string $value): string
    {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $normalized !== false ? $normalized : $value;
    }
}
