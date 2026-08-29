<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Services\PublishedDocumentExtractionRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Remplacement contrôlé de l'extraction d'un document, à partir d'une cible
 * complète mesurée contre son PDF source.
 *
 * L'autorisation suit l'état du document, pas la seule route : un document
 * déjà publié — même provisoirement retiré du public — ne se répare que sous
 * la responsabilité d'un administrateur, car l'écriture atteint le corpus
 * public. Un document en cours de curation, jamais publié, reste ouvert aux
 * éditeurs : c'est le moment prévu pour le corriger.
 *
 * @group Corpus — remplacement d'extraction
 */
class PublishedDocumentExtractionRepairController extends Controller
{
    public function snapshot(
        Request $request,
        LegalDocument $document,
        PublishedDocumentExtractionRepairService $service,
    ): JsonResponse {
        $this->assertMayRepair($request, $document);

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
        $this->assertMayRepair($request, $document);

        $validated = $request->validate([
            'execute' => ['required', 'boolean'],
            'expected_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'motif' => ['required', 'string', 'min:20', 'max:1000'],
            // Confirmation chiffrée d'un retrait d'articles : l'appelant doit
            // répéter le nombre annoncé par le dry-run, pas cocher une case.
            'confirm_deletions' => ['sometimes', 'integer', 'min:0'],
            'target' => ['required', 'array'],
            'target.schema_version' => ['required', 'integer', 'in:1'],
            'target.document_id' => ['required', 'uuid'],
            'target.source_pdf' => ['required', 'array'],
            'target.source_pdf.sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            // Les actes courts peuvent légitimement porter leurs articles à la
            // racine, sans aucune division. Le snapshot produit alors `[]` et
            // doit rester directement rejouable.
            'target.nodes' => ['present', 'array', 'max:5000'],
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
                isset($validated['confirm_deletions']) ? (int) $validated['confirm_deletions'] : null,
            )
            : $service->dryRun(
                $document,
                $validated['target'],
                $validated['expected_fingerprint'],
            );

        return $this->success(
            $result,
            $validated['execute']
                ? 'Extraction d’un document déjà publié réparée et vérifiée avec succès.'
                : 'Dry-run validé, aucune écriture effectuée.'
        );
    }

    private function assertMayRepair(Request $request, LegalDocument $document): void
    {
        if ($document->hasEverBeenPublished() && ! $request->user()->hasRole('admin')) {
            abort(403, 'La réparation d’un document déjà publié est réservée aux administrateurs.');
        }
    }
}
