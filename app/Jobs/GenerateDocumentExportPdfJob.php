<?php

namespace App\Jobs;

use App\Models\LegalDocument;
use App\Services\DocumentExportPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pré-génère le PDF Mibeko (export « partage ») d'un document publié, en
 * tâche de fond.
 *
 * Le rendu DomPDF est coûteux sur un gros document : sans pré-chauffage, le
 * premier partage après publication ou modification déclencherait ce rendu
 * en synchrone dans une requête web (cf. incident VPS saturé). Dispatché par
 * LegalDocumentObserver à chaque sauvegarde d'un document publié.
 */
class GenerateDocumentExportPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public string $documentId) {}

    public function handle(DocumentExportPdfService $exporter): void
    {
        $document = LegalDocument::find($this->documentId);

        // Le document a pu être dépublié ou supprimé entre le dispatch et
        // l'exécution : pas de rendu pour un document qui n'est plus public.
        if (! $document || $document->curation_status !== LegalDocument::STATUS_PUBLISHED) {
            return;
        }

        // Idempotent : un dispatch redondant (plusieurs sauvegardes dans la
        // même requête, ou repli synchrone déjà passé par là) ne refait pas
        // le rendu si le cache est déjà à jour pour cette version.
        if ($exporter->cachedPath($document)) {
            return;
        }

        $exporter->generate($document);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning("Échec de la pré-génération du PDF d'export pour le document {$this->documentId}", [
            'exception' => $exception->getMessage(),
        ]);
    }
}
