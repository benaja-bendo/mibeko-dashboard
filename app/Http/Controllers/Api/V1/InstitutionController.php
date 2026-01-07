<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InstitutionResource;
use App\Models\Institution;
use Illuminate\Http\JsonResponse;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @group Institutions
 */
class InstitutionController extends Controller
{
    /**
     * List institutions.
     */
    public function index(): JsonResponse
    {
        $institutions = QueryBuilder::for(Institution::class)
            ->allowedFilters(['nom', 'sigle'])
            ->allowedSorts(['nom', 'sigle'])
            ->get();

        return $this->success(
            InstitutionResource::collection($institutions),
            'Institutions récupérées avec succès'
        );
    }
}
