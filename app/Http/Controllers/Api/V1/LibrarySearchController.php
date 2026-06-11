<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\SearchesArticles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @group Library Search
 *
 * Recherche documentaire de la Bibliothèque (poste de travail web).
 *
 * Moteur 100 % PostgreSQL (full-text `tsvector` français + `ts_rank`), sans
 * embedding ni génération IA : déterministe, instantané et totalement
 * découplé de l'IA. La synthèse et l'explication par l'IA sont exposées
 * séparément et à la demande par {@see LibraryAiController}.
 */
class LibrarySearchController extends Controller
{
    use SearchesArticles;

    /**
     * Recherche full-text dans la base juridique.
     *
     * Retourne systématiquement une liste paginée d'articles classés par
     * pertinence (ou par date), jamais de réponse générée par l'IA.
     *
     * @queryParam q string required Requête : notion, numéro d'article ou titre. Example: travail forcé
     * @queryParam type string Code d'un type de document.
     * @queryParam institution_id string UUID de l'institution émettrice.
     * @queryParam legal_scope string Périmètre : national, ohada ou communautaire.
     * @queryParam date_from date Borne basse de publication (YYYY-MM-DD).
     * @queryParam date_to date Borne haute de publication (YYYY-MM-DD).
     * @queryParam document_id string Restreindre la recherche à un document.
     * @queryParam sort string Tri : relevance (défaut), date_desc ou date_asc.
     * @queryParam per_page integer Résultats par page (1 à 50). Default: 12.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2'],
            'type' => ['nullable', 'string', 'exists:document_types,code'],
            'institution_id' => ['nullable', 'string', 'exists:institutions,id'],
            'legal_scope' => ['nullable', 'string', 'in:national,ohada,communautaire'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'document_id' => ['nullable', 'string', 'exists:legal_documents,id'],
            'tag' => ['nullable', 'string'],
            'sort' => ['nullable', 'string', 'in:relevance,date_desc,date_asc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = $this->lexicalArticleSearch(
            search: $validated['q'],
            filters: [
                'type' => $validated['type'] ?? null,
                'institution_id' => $validated['institution_id'] ?? null,
                'legal_scope' => $validated['legal_scope'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'document_id' => $validated['document_id'] ?? null,
                'tag' => $validated['tag'] ?? null,
            ],
            sort: $validated['sort'] ?? 'relevance',
            perPage: (int) ($validated['per_page'] ?? 12),
        );

        return $this->paginatedSuccess($paginator, null, 'Résultats de recherche récupérés avec succès');
    }

    /**
     * Suggestions de recherche en temps réel.
     *
     * Autocomplétion de la barre de recherche : pour quelques caractères tapés,
     * retourne tout ce qui peut correspondre dans le fonds — titres de textes,
     * articles par numéro, et passages du contenu même des lois (extrait
     * surligné). L'utilisateur voit ainsi immédiatement que titre, numéro et
     * texte intégral sont tous interrogeables.
     *
     * @queryParam q string required Début de requête (2 caractères minimum). Example: trav
     */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $q = trim($validated['q']);

        return response()->json([
            'success' => true,
            'data' => [
                'documents' => $this->suggestDocuments($q),
                'articles' => $this->suggestArticles($q),
                'passages' => $this->suggestPassages($q),
            ],
        ]);
    }

    /**
     * Textes publiés dont le titre contient tous les mots tapés.
     *
     * @return array<int, object>
     */
    private function suggestDocuments(string $q, int $limit = 4): array
    {
        $words = $this->suggestWords($q);
        if ($words === []) {
            return [];
        }

        $query = DB::table('legal_documents as ld')
            ->join('document_types as dt', 'ld.type_code', '=', 'dt.code')
            ->whereNull('ld.deleted_at')
            ->where('ld.curation_status', 'published');

        foreach ($words as $word) {
            $query->where('ld.titre_officiel', 'ILIKE', "%{$word}%");
        }

        return $query->orderBy('dt.niveau_hierarchique')
            ->orderByDesc('ld.date_publication')
            ->limit($limit)
            ->get([
                'ld.id',
                'ld.titre_officiel as title',
                'dt.code as type_code',
                'dt.nom as type_name',
            ])
            ->all();
    }

    /**
     * Articles ciblés par numéro (« article 49 », « 49 code du travail »…),
     * le reste de la requête filtrant le titre du document parent.
     *
     * @return array<int, array<string, mixed>>
     */
    private function suggestArticles(string $q, int $limit = 4): array
    {
        $number = null;
        $rest = '';

        if (preg_match('/\bart(?:icle)?s?\.?\s*(\d+[\w.-]*)/iu', $q, $matches)) {
            $number = $matches[1];
            $rest = trim(preg_replace('/\bart(?:icle)?s?\.?\s*\d+[\w.-]*/iu', ' ', $q) ?? '');
        } elseif (preg_match('/^\s*(\d+[\w.-]*)\s*(.*)$/u', $q, $matches)) {
            $number = $matches[1];
            $rest = trim($matches[2]);
        }

        if ($number === null) {
            return [];
        }

        $query = $this->baseArticleQuery()->where('a.numero_article', $number);
        foreach ($this->suggestWords($rest) as $word) {
            $query->where('ld.titre_officiel', 'ILIKE', "%{$word}%");
        }

        return $query->orderBy('ld.titre_officiel')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->article_id,
                'number' => $item->numero_article,
                'document_id' => $item->document_id,
                'document_title' => $item->document_title,
                'type_code' => $item->document_type_code,
            ])
            ->all();
    }

    /**
     * Passages du texte des lois correspondant à la frappe (recherche
     * full-text par préfixe sur l'index GIN), avec extrait surligné.
     *
     * Les correspondances sont balisées [[…]] (et non en HTML) : le front les
     * transforme en nœuds React sûrs, sans injection possible.
     *
     * @return array<int, array<string, mixed>>
     */
    private function suggestPassages(string $q, int $limit = 4): array
    {
        $prefixQuery = collect($this->suggestWords($q))
            ->map(fn (string $word) => $word.':*')
            ->implode(' & ');

        if ($prefixQuery === '') {
            return [];
        }

        $matches = $this->baseArticleQuery()
            ->whereRaw("av.search_tsv @@ to_tsquery('french', ?)", [$prefixQuery])
            ->selectRaw("ts_rank(av.search_tsv, to_tsquery('french', ?)) as rank", [$prefixQuery])
            ->orderByDesc('rank')
            ->limit($limit)
            ->get();

        return $matches->map(function ($item) use ($prefixQuery) {
            $headline = DB::selectOne(
                "select ts_headline('french', ?, to_tsquery('french', ?), ?) as snippet",
                [
                    $item->contenu_texte,
                    $prefixQuery,
                    'StartSel=[[,StopSel=]],MaxWords=20,MinWords=6,ShortWord=2',
                ],
            );

            return [
                'id' => $item->article_id,
                'number' => $item->numero_article,
                'document_id' => $item->document_id,
                'document_title' => $item->document_title,
                'snippet' => $headline->snippet ?? Str::limit($item->contenu_texte, 120),
            ];
        })->all();
    }

    /**
     * Découpe la frappe en mots exploitables (≥ 2 caractères, sans ponctuation).
     *
     * @return array<int, string>
     */
    private function suggestWords(string $q): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $q) ?? '';

        return array_values(array_filter(
            explode(' ', trim($clean)),
            fn (string $word) => mb_strlen($word) >= 2,
        ));
    }
}
