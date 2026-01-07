<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DocumentTypeResource;
use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @group Document Types
 */
class DocumentTypeController extends Controller
{
    /**
     * List document types.
     */
    public function index(): JsonResponse
    {
        $types = QueryBuilder::for(DocumentType::class)
            ->allowedFilters(['nom', 'code', 'niveau_hierarchique'])
            ->allowedSorts(['nom', 'niveau_hierarchique', 'code'])
            ->get();

        return $this->success(
            DocumentTypeResource::collection($types),
            'Types de documents récupérés avec succès'
        );
    }
}
