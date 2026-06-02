<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LegalDocumentResource;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @group Legal Documents
 *
 * API endpoints for managing legal documents.
 */
class LegalDocumentController extends Controller
{
    /** Shared filter definitions used across index and search. */
    private function allowedFilters(): array
    {
        return [
            AllowedFilter::partial('titre_officiel'),
            'type_code',
            'institution_id',
            'official_journal_id',
            'statut',
            'curation_status',
            'document_role',
            AllowedFilter::callback('recent', function ($query, $value) {
                // ?filter[recent]=7  → modifiés dans les 7 derniers jours
                $days = is_numeric($value) ? (int) $value : 7;
                $query->where('updated_at', '>=', now()->subDays($days));
            }),
        ];
    }

    /** Shared sort definitions. */
    private function allowedSorts(): array
    {
        return ['titre_officiel', 'date_signature', 'date_publication', 'created_at', 'updated_at', 'curation_status', 'statut'];
    }

    /**
     * List legal documents.
     *
     * @queryParam filter[titre_officiel] string Filter by partial official title.
     * @queryParam filter[type_code] string Filter by document type code (e.g., "LOI", "CODE").
     * @queryParam filter[institution_id] string UUID of the institution.
     * @queryParam filter[curation_status] string Filter by curation status.
     * @queryParam filter[statut] string Filter by validity status.
     * @queryParam filter[recent] int Filter documents modified in the last N days.
     * @queryParam per_page int Number of items per page (default 20, max 100).
     * @queryParam sort string Sort field (e.g., "titre_officiel", "-updated_at").
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $documents = QueryBuilder::for(LegalDocument::class)
            ->with(['institution', 'type'])
            ->withCount(['articles', 'relations', 'tags'])
            ->allowedFilters($this->allowedFilters())
            ->allowedSorts($this->allowedSorts())
            ->latest('updated_at')
            ->paginate($perPage);

        return $this->paginatedSuccess(
            $documents,
            LegalDocumentResource::class,
            'Documents récupérés avec succès'
        );
    }

    /**
     * Search legal documents (full-text on titre_officiel + reference_nor).
     *
     * @queryParam q string Search query.
     * @queryParam per_page int Number of items per page (default 20, max 100).
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $perPage = min((int) $request->input('per_page', 20), 100);

        $documents = QueryBuilder::for(LegalDocument::class)
            ->with(['institution', 'type'])
            ->withCount(['articles', 'relations', 'tags'])
            ->when(! empty($query), function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('titre_officiel', 'ilike', "%{$query}%")
                        ->orWhere('reference_nor', 'ilike', "%{$query}%")
                        ->orWhere('stock_code', 'ilike', "%{$query}%");
                });
            })
            ->allowedFilters($this->allowedFilters())
            ->allowedSorts($this->allowedSorts())
            ->latest('updated_at')
            ->paginate($perPage);

        return $this->paginatedSuccess(
            $documents,
            LegalDocumentResource::class,
            'Résultats de la recherche de documents'
        );
    }

    /**
     * Get a legal document.
     *
     * Returns a single legal document with its articles, relations, and their latest versions.
     */
    public function show(string $id): JsonResponse
    {
        $document = QueryBuilder::for(LegalDocument::class)
            ->with(['institution', 'type', 'articles.latestVersion', 'relations.targetDocument'])
            ->withCount(['articles', 'relations', 'tags'])
            ->findOrFail($id);

        return $this->success(
            new LegalDocumentResource($document),
            'Document récupéré avec succès'
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);

        DB::transaction(function () use ($id, $document) {
            DocumentRelation::where('source_doc_id', $id)
                ->orWhere('target_doc_id', $id)
                ->delete();

            $document->delete();
        });

        return $this->success(null, 'Document supprimé avec succès');
    }

    /**
     * Bulk update documents (editor + admin only).
     *
     * @bodyParam ids string[] required List of document UUIDs.
     * @bodyParam action string required Action to perform: set_curation_status, set_statut.
     * @bodyParam value string required New value for the action.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['uuid'],
            'action' => ['required', 'string', 'in:set_curation_status,set_statut'],
            'value' => ['required', 'string'],
        ]);

        $allowedCurationStatuses = [
            LegalDocument::STATUS_DRAFT,
            LegalDocument::STATUS_REVIEW,
            LegalDocument::STATUS_VALIDATED,
            LegalDocument::STATUS_PUBLISHED,
        ];
        $allowedStatuts = ['vigueur', 'abroge', 'projet'];

        if ($request->action === 'set_curation_status' && ! in_array($request->value, $allowedCurationStatuses, true)) {
            return $this->error(null, 'Valeur de statut de curation invalide.', 422);
        }

        if ($request->action === 'set_statut' && ! in_array($request->value, $allowedStatuts, true)) {
            return $this->error(null, 'Valeur de statut de validité invalide.', 422);
        }

        $column = $request->action === 'set_curation_status' ? 'curation_status' : 'statut';

        $updated = DB::transaction(function () use ($request, $column) {
            return LegalDocument::whereIn('id', $request->ids)
                ->update([$column => $request->value, 'updated_at' => now()]);
        });

        return $this->success(
            ['updated_count' => $updated],
            "{$updated} document(s) mis à jour avec succès."
        );
    }
}
