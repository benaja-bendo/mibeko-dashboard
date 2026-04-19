<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait SearchesArticles
{
    /**
     * Search articles (Hybrid: Vector + Full-Text).
     */
    protected function searchArticles(string $query, int $limit = 5, ?string $documentType = null, ?string $documentTitle = null): array
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
        if (!empty($documentType)) {
            $results->where('dt.code', 'ILIKE', "%$documentType%");
        }
        if (!empty($documentTitle)) {
            $results->where('ld.titre_officiel', 'ILIKE', "%$documentTitle%");
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
     * Format query for Postgres to_tsquery
     */
    protected function formatTsQuery(string $query): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query);
        $parts = array_filter(explode(' ', trim($clean)), function ($w) {
            return mb_strlen($w) > 3;
        });

        if (empty($parts)) {
            return '';
        }

        return implode(' | ', $parts);
    }
}
