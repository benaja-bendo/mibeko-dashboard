<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\SearchesArticles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
