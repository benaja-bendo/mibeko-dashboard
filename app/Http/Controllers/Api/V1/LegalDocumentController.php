<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LegalDocumentResource;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @group Legal Documents
 *
 * API endpoints for managing legal documents.
 */
class LegalDocumentController extends Controller
{
    /**
     * List legal documents.
     *
     * Returns a paginated list of legal documents with their institutions and types.
     * Supports filtering and sorting via query parameters.
     * 
     * @queryParam filter[titre_officiel] string Filter by partial official title.
     * @queryParam filter[type_code] string Filter by document type code (e.g., "LOI", "CODE").
     * @queryParam filter[institution_id] string UUID of the institution.
     * @queryParam sort string Sort field (e.g., "titre_officiel", "-created_at").
     */
    public function index(Request $request): JsonResponse
    {
        $documents = QueryBuilder::for(LegalDocument::class)
            ->with(['institution', 'type'])
            ->allowedFilters([
                AllowedFilter::partial('titre_officiel'),
                'type_code',
                'institution_id',
                'statut'
            ])
            ->allowedSorts(['titre_officiel', 'date_signature', 'created_at'])
            ->latest()
            ->paginate(20);

        return $this->paginatedSuccess(
            $documents,
            LegalDocumentResource::class,
            'Documents récupérés avec succès'
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
            ->findOrFail($id);

        return $this->success(
            new LegalDocumentResource($document),
            'Document récupéré avec succès'
        );
    }
}
