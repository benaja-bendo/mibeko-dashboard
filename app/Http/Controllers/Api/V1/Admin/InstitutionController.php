<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreInstitutionRequest;
use App\Http\Requests\Api\V1\Admin\UpdateInstitutionRequest;
use App\Http\Resources\V1\Admin\InstitutionResource;
use App\Models\Institution;
use Illuminate\Http\JsonResponse;

/**
 * Gestion des institutions émettrices depuis l'espace admin.
 *
 * @group Admin / Référentiels
 */
class InstitutionController extends Controller
{
    public function index(): JsonResponse
    {
        $institutions = Institution::withCount('legalDocuments')
            ->orderBy('nom')
            ->get();

        return $this->success(
            InstitutionResource::collection($institutions),
            'Institutions récupérées avec succès'
        );
    }

    public function store(StoreInstitutionRequest $request): JsonResponse
    {
        $institution = Institution::create([
            'nom' => $request->validated('name'),
            'sigle' => $request->validated('acronym'),
        ]);

        $institution->loadCount('legalDocuments');

        return $this->success(
            new InstitutionResource($institution),
            'Institution créée avec succès',
            201
        );
    }

    public function update(UpdateInstitutionRequest $request, Institution $institution): JsonResponse
    {
        $institution->update([
            'nom' => $request->validated('name'),
            'sigle' => $request->validated('acronym'),
        ]);

        $institution->loadCount('legalDocuments');

        return $this->success(
            new InstitutionResource($institution),
            'Institution mise à jour avec succès'
        );
    }

    /**
     * Supprime une institution — refusé si des documents y sont rattachés.
     */
    public function destroy(Institution $institution): JsonResponse
    {
        $usage = $institution->legalDocuments()->count();

        if ($usage > 0) {
            return $this->error(
                ['documents_count' => $usage],
                "Impossible de supprimer : {$usage} document(s) sont rattachés à cette institution.",
                409
            );
        }

        $institution->delete();

        return $this->success(null, 'Institution supprimée avec succès');
    }
}
