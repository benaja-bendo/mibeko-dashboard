<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Services\PublishedDocumentExtractionRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réparation exceptionnelle d'une extraction déjà publiée.
 *
 * @group Admin / Corpus publié
 */
class PublishedDocumentExtractionRepairController extends Controller
{
    public function snapshot(
        LegalDocument $document,
        PublishedDocumentExtractionRepairService $service,
    ): JsonResponse {
        return $this->success(
            $service->snapshot($document),
            'Snapshot de retour arrière généré avec succès.'
        );
    }

    public function replace(
        Request $request,
        LegalDocument $document,
        PublishedDocumentExtractionRepairService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'execute' => ['required', 'boolean'],
            'expected_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'motif' => ['required', 'string', 'min:20', 'max:1000'],
            'target' => ['required', 'array'],
            'target.schema_version' => ['required', 'integer', 'in:1'],
            'target.document_id' => ['required', 'uuid'],
            'target.source_pdf' => ['required', 'array'],
            'target.source_pdf.sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'target.nodes' => ['required', 'array', 'min:1', 'max:5000'],
            'target.nodes.*.key' => ['required', 'string', 'max:100'],
            'target.nodes.*.id' => ['sometimes', 'uuid'],
            'target.nodes.*.parent' => ['nullable', 'string', 'max:100'],
            'target.nodes.*.type' => ['required', 'string', 'max:50'],
            'target.nodes.*.number' => ['nullable', 'string', 'max:50'],
            'target.nodes.*.title' => ['nullable', 'string'],
            'target.nodes.*.order' => ['required', 'integer', 'min:0'],
            'target.articles' => ['required', 'array', 'min:1', 'max:10000'],
            'target.articles.*.id' => ['sometimes', 'uuid'],
            'target.articles.*.number' => ['required', 'string', 'max:50'],
            'target.articles.*.parent' => ['nullable', 'string', 'max:100'],
            'target.articles.*.order' => ['required', 'integer', 'min:0'],
            'target.articles.*.content' => ['required', 'string'],
            'target.articles.*.source_locator' => ['sometimes', 'array'],
            'target.articles.*.page' => ['sometimes', 'integer', 'min:1'],
            'target.articles.*.page_end' => ['sometimes', 'integer', 'min:1'],
        ]);

        $result = $validated['execute']
            ? $service->execute(
                $document,
                $validated['target'],
                $validated['expected_fingerprint'],
                trim($validated['motif']),
                (string) $request->user()->id,
            )
            : $service->dryRun(
                $document,
                $validated['target'],
                $validated['expected_fingerprint'],
            );

        return $this->success(
            $result,
            $validated['execute']
                ? 'Extraction publiée réparée et vérifiée avec succès.'
                : 'Dry-run validé, aucune écriture effectuée.'
        );
    }
}
