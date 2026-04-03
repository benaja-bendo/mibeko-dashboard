<?php

namespace Database\Seeders;

use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use App\Services\DocumentImportService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CongoJournalOfficielSeeder extends Seeder
{
    /**
     * Paths to data directories.
     */
    protected string $jsonPath;

    protected string $pdfPath;

    protected DocumentImportService $importService;

    public function __construct(DocumentImportService $importService)
    {
        $this->jsonPath = database_path('data/json');
        $this->pdfPath = database_path('data/pdf');
        $this->importService = $importService;
    }

    public function run(): void
    {
        // Disable automatic embedding generation during seeding
        ArticleVersionObserver::$shouldSkipEmbeddings = true;

        $this->command->info('🚀 Démarrage du seeding Mibeko...');

        // 1. Ensure directories exist
        if (! File::isDirectory($this->jsonPath)) {
            $this->command->error("❌ Le dossier JSON est introuvable : {$this->jsonPath}");

            return;
        }

        // 2. Scan for JSON files
        $files = File::glob("{$this->jsonPath}/*.json");
        sort($files);

        $limit = (int) env('MIBEKO_SEED_LIMIT', 0);
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }
        $count = count($files);

        if ($count === 0) {
            $this->command->warn("⚠️ Aucun fichier JSON trouvé dans {$this->jsonPath}");

            return;
        }

        $this->command->info("📦 {$count} fichiers trouvés. Traitement en cours...");

        // 4. Process each file
        $bar = $this->command->getOutput()->createProgressBar($count);
        $bar->start();

        foreach ($files as $filePath) {
            $this->processFile($filePath);
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info('✅ Seeding terminé avec succès !');
    }

    private function processFile(string $jsonFilePath): void
    {
        try {
            $content = File::get($jsonFilePath);
            $jsonData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->command->error('Erreur JSON dans '.basename($jsonFilePath).': '.json_last_error_msg());

                return;
            }

            $baseName = pathinfo($jsonFilePath, PATHINFO_FILENAME);
            $pdfLocalPath = "{$this->pdfPath}/{$baseName}.pdf";

            $pdfUploadedPath = null;
            if (File::exists($pdfLocalPath)) {
                $pdfUploadedPath = $this->handleFileUpload($pdfLocalPath, "documents/pdfs/{$baseName}.pdf");
            }

            // Upload JSON as well
            $jsonUploadedPath = $this->handleFileUpload($jsonFilePath, "documents/jsons/{$baseName}.json");

            $textes = $jsonData['textes'] ?? [$jsonData];

            foreach ($textes as $index => $texteData) {
                $refNor = $texteData['reference_nor']
                    ?? (isset($texteData['numero_texte']) ? Str::slug($texteData['numero_texte']) : null);

                if (! $refNor) {
                    // Fallback: Use filename. If multiple texts in file, append index.
                    $refNor = Str::slug($baseName);
                    if (count($textes) > 1) {
                        $refNor .= '-'.($index + 1);
                    }
                }

                if (LegalDocument::where('reference_nor', $refNor)->exists()) {
                    continue;
                }

                DB::transaction(function () use ($texteData, $pdfUploadedPath, $jsonUploadedPath, $refNor) {
                    $this->importDocument($texteData, $pdfUploadedPath, $jsonUploadedPath, $refNor);
                });
            }

        } catch (\Exception $e) {
            $this->command->error('Erreur lors du traitement de '.basename($jsonFilePath).': '.$e->getMessage());
        }
    }

    private function handleFileUpload(string $localPath, string $destinationPath): ?string
    {
        try {
            // On utilise le disque par défaut configuré (local ou s3)
            $disk = Storage::disk(config('filesystems.default'));

            // Check if file already exists to avoid re-uploading every time
            if ($disk->exists($destinationPath)) {
                return $destinationPath;
            }

            $disk->put($destinationPath, File::get($localPath));

            return $destinationPath;
        } catch (\Exception $e) {
            $this->command->error("Erreur lors de l'upload de {$destinationPath}: ".$e->getMessage());

            return null;
        }
    }

    /**
     * Tries to parse a date or return a safe fallback.
     */
    private function parseSafeDate(?string $dateString): Carbon
    {
        if (empty($dateString)) {
            return now();
        }

        try {
            return Carbon::parse($dateString);
        } catch (\Exception $e) {
            // Try to extract a year if possible (e.g. "26 mars 2025 portant...")
            if (preg_match('/(19|20)\d{2}/', $dateString, $matches)) {
                return Carbon::createFromDate($matches[0], 1, 1);
            }

            return now();
        }
    }

    private function importDocument(array $data, ?string $pdfPath, ?string $jsonPath, string $refNor): void
    {
        // 1. Determine Type Code
        $typeCode = $data['type_code'] ?? 'LOI';

        if (! isset($data['type_code'])) {
            $num = strtolower($data['numero_texte'] ?? '');
            if (str_contains($num, 'décret')) {
                $typeCode = 'DEC';
            }
            if (str_contains($num, 'arrêté')) {
                $typeCode = 'ARR';
            }
            if (str_contains($num, 'constitution')) {
                $typeCode = 'CONST';
            }
            if (str_contains($num, 'ordonnance')) {
                $typeCode = 'ORD';
            }
        }

        $publicationDate = $this->parseSafeDate($data['date_publication'] ?? $data['date_signature'] ?? null);

        // 2. Determine Title
        $title = $data['titre_officiel'] ?? $data['intitule_long'] ?? 'Document sans titre';

        // 3. Create Document (using firstOrCreate)
        $document = LegalDocument::firstOrCreate(
            ['reference_nor' => $refNor],
            [
                'type_code' => $typeCode,
                'titre_officiel' => $title,
                'date_publication' => $publicationDate,
                'date_signature' => $this->parseSafeDate($data['date_signature'] ?? null),
                'statut' => 'vigueur',
                'curation_status' => 'published',
            ]
        );

        // If the document already existed, don't recreate articles/nodes
        if (! $document->wasRecentlyCreated) {
            return;
        }

        if ($pdfPath) {
            $document->mediaFiles()->create([
                'file_path' => $pdfPath,
                'mime_type' => 'application/pdf',
                'description' => 'Original signé',
            ]);
        }

        if ($jsonPath) {
            $document->mediaFiles()->create([
                'file_path' => $jsonPath,
                'mime_type' => 'application/json',
                'description' => 'Données structurées JSON',
            ]);
        }

        // 4. Process Content using DocumentImportService
        $this->importService->importContent($document, $data);
    }
}
