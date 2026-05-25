<?php

namespace App\Jobs;

use App\Models\LegalDocument;
use App\Services\DocumentIngestionService;
use App\Services\LegalTextParser;
use App\Services\MinerUClient;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessDocumentExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes (polling MinerU takes time)

    protected string $documentId;

    protected string $runId;

    public function __construct(string $documentId, string $runId)
    {
        $this->documentId = $documentId;
        $this->runId = $runId;
    }

    public function handle(MinerUClient $mineru, LegalTextParser $parser, DocumentIngestionService $ingester): void
    {
        Log::info("Début de l'extraction pour le document: {$this->documentId}, run: {$this->runId}");

        $document = LegalDocument::with('mediaFiles')->find($this->documentId);
        if (! $document) {
            Log::error("Document non trouvé: {$this->documentId}");
            $this->markRunFailed('Document introuvable');

            return;
        }

        $mediaFile = $document->mediaFiles->first();
        if (! $mediaFile) {
            Log::error("Aucun fichier PDF associé au document: {$this->documentId}");
            $this->markRunFailed('Fichier PDF manquant');

            return;
        }

        // Marquer le run comme running
        DB::table('extraction_runs')->where('id', $this->runId)->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // 1. Obtenir le contenu du PDF (S3 ou local)
            $disk = str_contains($mediaFile->file_path, 's3') ? 's3' : config('filesystems.default');
            $fileContent = Storage::disk($disk)->get($mediaFile->file_path);

            if (! $fileContent) {
                throw new Exception("Impossible de lire le fichier: {$mediaFile->file_path}");
            }

            $filename = basename($mediaFile->file_path);

            // 2. Appel MinerU
            $mineruResult = $mineru->extractPdf($fileContent, $filename);
            $markdown = $mineruResult['markdown'];

            // 3. Sauvegarder l'extraction brute
            $extractionId = (string) Str::uuid();
            DB::table('text_extractions')->insert([
                'id' => $extractionId,
                'extraction_run_id' => $this->runId,
                'document_id' => $this->documentId,
                'media_file_id' => $mediaFile->id,
                'entity_type' => 'DOCUMENT',
                'raw_text' => $markdown,
                'meta' => json_encode($mineruResult['metadata']),
                'extracted_at' => now(),
            ]);

            // 4. Parser le Markdown
            $structure = $parser->parse($markdown);

            // 5. Ingestion dans la base de données (Transaction)
            DB::transaction(function () use ($document, $structure, $extractionId, $ingester) {
                $sortOrder = 1;
                $ingester->ingestStructure($document, $structure, null, $sortOrder, $extractionId);

                $document->update([
                    'extraction_status' => 'succeeded',
                ]);
            });

            // 6. Succès
            DB::table('extraction_runs')->where('id', $this->runId)->update([
                'status' => 'succeeded',
                'finished_at' => now(),
            ]);

            Log::info("Extraction terminée avec succès pour {$this->documentId}");

        } catch (Exception $e) {
            Log::error("Erreur d'extraction pour {$this->documentId}: ".$e->getMessage());
            $this->markRunFailed($e->getMessage());
        }
    }

    protected function markRunFailed(string $errorMsg)
    {
        DB::table('extraction_runs')->where('id', $this->runId)->update([
            'status' => 'failed',
            'meta' => json_encode(['error' => $errorMsg]),
            'finished_at' => now(),
        ]);

        LegalDocument::where('id', $this->documentId)->update([
            'extraction_status' => 'failed',
        ]);
    }
}
