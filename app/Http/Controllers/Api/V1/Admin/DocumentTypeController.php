<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreDocumentTypeRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDocumentTypeRequest;
use App\Http\Resources\V1\Admin\DocumentTypeResource;
use App\Models\DocumentType;
use Illuminate\Http\JsonResponse;

/**
 * Gestion des types de documents (« types de loi ») depuis l'espace admin.
 *
 * Remplace l'édition par seeder : le SystemRequirementsSeeder reste le socle
 * canonique idempotent, ce contrôleur gère l'évolution au quotidien.
 *
 * @group Admin / Référentiels
 */
class DocumentTypeController extends Controller
{
    /**
     * Liste des types, triés par niveau hiérarchique, avec leur usage.
     */
    public function index(): JsonResponse
    {
        $types = DocumentType::withCount('legalDocuments')
            ->orderBy('niveau_hierarchique')
            ->get();

        return $this->success(
            DocumentTypeResource::collection($types),
            'Types de documents récupérés avec succès'
        );
    }

    /**
     * Crée un nouveau type. Le code (clé primaire) est figé une fois créé.
     */
    public function store(StoreDocumentTypeRequest $request): JsonResponse
    {
        $type = DocumentType::create([
            'code' => $request->validated('code'),
            'nom' => $request->validated('name'),
            'niveau_hierarchique' => $request->validated('hierarchy_level'),
        ]);

        $type->loadCount('legalDocuments');

        return $this->success(
            new DocumentTypeResource($type),
            'Type de document créé avec succès',
            201
        );
    }

    /**
     * Met à jour le libellé et le niveau hiérarchique (jamais le code).
     */
    public function update(UpdateDocumentTypeRequest $request, DocumentType $documentType): JsonResponse
    {
        $documentType->update([
            'nom' => $request->validated('name'),
            'niveau_hierarchique' => $request->validated('hierarchy_level'),
        ]);

        $documentType->loadCount('legalDocuments');

        return $this->success(
            new DocumentTypeResource($documentType),
            'Type de document mis à jour avec succès'
        );
    }

    /**
     * Supprime un type — refusé s'il est encore utilisé par des documents,
     * pour préserver l'intégrité de legal_documents.type_code.
     */
    public function destroy(DocumentType $documentType): JsonResponse
    {
        $usage = $documentType->legalDocuments()->count();

        if ($usage > 0) {
            return $this->error(
                ['documents_count' => $usage],
                "Impossible de supprimer : {$usage} document(s) utilisent ce type.",
                409
            );
        }

        $documentType->delete();

        return $this->success(null, 'Type de document supprimé avec succès');
    }
}
