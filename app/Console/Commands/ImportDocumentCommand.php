<?php

namespace App\Console\Commands;

use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use App\Services\DocumentImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportDocumentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mibeko:import-document
                            {document_id : L\'ID du document à importer}
                            {--limit=0 : Limite du nombre d\'articles à importer (0 = tout importer)}
                            {--skip-embeddings : Ne pas générer les embeddings lors de l\'import}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importe le fichier JSON structuré d\'un document dans la base de données, avec possibilité de limiter le nombre d\'articles.';

    /**
     * Execute the console command.
     */
    public function handle(DocumentImportService $importService)
    {
        $documentId = $this->argument('document_id');
        $limit = (int) $this->option('limit');
        $skipEmbeddings = $this->option('skip-embeddings');

        $document = LegalDocument::find($documentId);

        if (! $document) {
            $this->error("Document avec l'ID {$documentId} introuvable.");

            return 1;
        }

        $jsonFile = $document->mediaFiles()->where('mime_type', 'application/json')->first();

        if (! $jsonFile) {
            $this->error("Aucun fichier JSON structuré trouvé pour ce document.");

            return 1;
        }

        $this->info("Récupération du fichier JSON depuis {$jsonFile->file_path}...");

        $jsonContent = Storage::disk('s3')->get($jsonFile->file_path);

        if (! $jsonContent) {
            $this->error("Impossible de lire le fichier JSON depuis le stockage S3/MinIO.");

            return 1;
        }

        $jsonData = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Erreur de décodage du JSON : " . json_last_error_msg());

            return 1;
        }

        $this->info("Début de l'importation...");
        
        if ($limit > 0) {
            $this->info("Limite définie à {$limit} article(s).");
        }
        
        if ($skipEmbeddings) {
            $this->info("Génération des embeddings désactivée pour cet import.");
            ArticleVersionObserver::$shouldSkipEmbeddings = true;
        }

        try {
            DB::transaction(function () use ($importService, $document, $jsonData, $limit) {
                $importService->importContent($document, $jsonData, $limit > 0 ? $limit : null);
            });
            
            $this->info("Importation terminée avec succès pour le document {$documentId}.");
        } catch (\Exception $e) {
            $this->error("Erreur lors de l'importation : " . $e->getMessage());
            
            return 1;
        } finally {
            if ($skipEmbeddings) {
                ArticleVersionObserver::$shouldSkipEmbeddings = false;
            }
        }

        return 0;
    }
}
