<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDossierGeneratedDocumentRequest;
use App\Http\Requests\Api\V1\StoreDossierPieceRequest;
use App\Http\Requests\Api\V1\StoreDossierReferenceRequest;
use App\Http\Resources\DossierGeneratedDocumentResource;
use App\Http\Resources\DossierPieceResource;
use App\Http\Resources\DossierReferenceResource;
use App\Models\Dossier;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Annexes des dossiers (web) : références juridiques, pièces et documents
 * générés. Ces trois collections vivaient jusqu'ici en localStorage côté web ;
 * elles sont désormais persistées pour un accès multi-appareils.
 *
 * Portée par propriétaire : comme la sync mobile et le CRUD « affaire », on
 * masque l'existence des dossiers d'autrui par un 404 (`ensureOwner`).
 */
class DossierAnnexController extends Controller
{
    use HttpResponses;

    /**
     * Ajoute une référence juridique à un dossier. Idempotent sur la cible
     * (`id` = UUID de l'article/document) : un doublon renvoie l'existant.
     */
    public function storeReference(StoreDossierReferenceRequest $request, Dossier $dossier): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        $validated = $request->validated();

        $reference = $dossier->references()->firstOrCreate(
            ['target_id' => $validated['id']],
            [
                'type' => $validated['type'],
                'title' => $validated['title'],
                'breadcrumb' => $validated['breadcrumb'] ?? null,
                'number' => $validated['number'] ?? null,
                'note' => $validated['note'] ?? null,
            ],
        );

        return $this->success(
            new DossierReferenceResource($reference),
            'Référence ajoutée.',
            $reference->wasRecentlyCreated ? 201 : 200,
        );
    }

    /**
     * Retire une référence d'un dossier (identifiée par l'UUID de sa cible).
     */
    public function destroyReference(Request $request, Dossier $dossier, string $target): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        $dossier->references()->where('target_id', $target)->delete();

        return $this->success(null, 'Référence retirée.');
    }

    /**
     * Ajoute une pièce (métadonnées) à un dossier.
     */
    public function storePiece(StoreDossierPieceRequest $request, Dossier $dossier): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        $piece = $dossier->pieces()->create([
            ...$request->validated(),
            'added_at' => now(),
        ]);

        return $this->success(new DossierPieceResource($piece), 'Pièce ajoutée.', 201);
    }

    /**
     * Retire une pièce d'un dossier.
     */
    public function destroyPiece(Request $request, Dossier $dossier, string $piece): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        $dossier->pieces()->whereKey($piece)->delete();

        return $this->success(null, 'Pièce retirée.');
    }

    /**
     * Ajoute un document généré (avec son HTML imprimable) à un dossier.
     */
    public function storeDocument(StoreDossierGeneratedDocumentRequest $request, Dossier $dossier): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        $document = $dossier->generatedDocuments()->create($request->validated());

        return $this->success(new DossierGeneratedDocumentResource($document), 'Document ajouté.', 201);
    }

    /**
     * Retire un document généré d'un dossier.
     */
    public function destroyDocument(Request $request, Dossier $dossier, string $document): JsonResponse
    {
        $this->ensureOwner($request, $dossier);

        $dossier->generatedDocuments()->whereKey($document)->delete();

        return $this->success(null, 'Document retiré.');
    }

    /**
     * Refuse l'accès aux dossiers d'autrui en masquant leur existence (404).
     */
    private function ensureOwner(Request $request, Dossier $dossier): void
    {
        abort_if($dossier->user_id !== $request->user()->id, 404);
    }
}
