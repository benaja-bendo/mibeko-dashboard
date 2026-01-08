<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CongoJournalOfficielSeeder extends Seeder
{
    /**
     * Paths to data directories.
     */
    protected string $jsonPath;
    protected string $pdfPath;

    public function __construct()
    {
        $this->jsonPath = database_path('data/json');
        $this->pdfPath = database_path('data/pdf');
    }

    public function run(): void
    {
        $this->command->info('🚀 Démarrage du seeding Mibeko...');

        // 1. Ensure directories exist
        if (!File::isDirectory($this->jsonPath)) {
            $this->command->error("❌ Le dossier JSON est introuvable : {$this->jsonPath}");
            return;
        }

        // 2. Scan for JSON files
        $files = File::glob("{$this->jsonPath}/*.json");
        $count = count($files);

        if ($count === 0) {
            $this->command->warn("⚠️ Aucun fichier JSON trouvé dans {$this->jsonPath}");
            return;
        }

        $this->command->info("📦 {$count} fichiers trouvés. Traitement en cours...");

        // 3. Initialize common data (Document Types)
        $this->ensureDocumentTypesExist();

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

    private function ensureDocumentTypesExist(): void
    {
        $types = [
            ['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 40],
            ['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 70],
            ['code' => 'ARR', 'nom' => 'Arrêté', 'niveau_hierarchique' => 80],
            ['code' => 'CONST', 'nom' => 'Constitution', 'niveau_hierarchique' => 0],
            ['code' => 'ORD', 'nom' => 'Ordonnance', 'niveau_hierarchique' => 60],
        ];

        foreach ($types as $type) {
            DocumentType::firstOrCreate(
                ['code' => $type['code']],
                $type
            );
        }
    }

    private function processFile(string $jsonFilePath): void
    {
        try {
            $content = File::get($jsonFilePath);
            $jsonData = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->command->error("Erreur JSON dans " . basename($jsonFilePath) . ": " . json_last_error_msg());
                return;
            }

            $baseName = pathinfo($jsonFilePath, PATHINFO_FILENAME);
            $pdfLocalPath = "{$this->pdfPath}/{$baseName}.pdf";

            $pdfUploadedPath = null;
            if (File::exists($pdfLocalPath)) {
                $pdfUploadedPath = $this->handlePdfUpload($pdfLocalPath, $baseName);
            }

            $textes = $jsonData['textes'] ?? [$jsonData];

            foreach ($textes as $texteData) {
                $refNor = Str::slug($texteData['numero_texte'] ?? $baseName . '-' . Str::random(5));

                if (LegalDocument::where('reference_nor', $refNor)->exists()) {
                    continue;
                }

                DB::transaction(function () use ($texteData, $pdfUploadedPath, $refNor) {
                    $this->importDocument($texteData, $pdfUploadedPath, $refNor);
                });
            }

        } catch (\Exception $e) {
            $this->command->error("Erreur lors du traitement de " . basename($jsonFilePath) . ": " . $e->getMessage());
        }
    }

    private function handlePdfUpload(string $localPath, string $baseName): ?string
    {
        try {
            $filename = "sources/{$baseName}.pdf";
            if (config('filesystems.disks.s3.bucket')) {
                 Storage::disk('s3')->put($filename, File::get($localPath));
                 return $filename;
            }
            return null;
        } catch (\Exception $e) {
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

    private function importDocument(array $data, ?string $pdfPath, string $refNor): void
    {
        $typeCode = 'LOI';
        $num = strtolower($data['numero_texte'] ?? '');
        if (str_contains($num, 'décret')) $typeCode = 'DEC';
        if (str_contains($num, 'arrêté')) $typeCode = 'ARR';
        if (str_contains($num, 'constitution')) $typeCode = 'CONST';
        if (str_contains($num, 'ordonnance')) $typeCode = 'ORD';

        $publicationDate = $this->parseSafeDate($data['date_publication'] ?? null);

        $document = LegalDocument::create([
            'type_code' => $typeCode,
            'reference_nor' => $refNor,
            'titre_officiel' => $data['intitule_long'] ?? 'Document sans titre',
            'date_publication' => $publicationDate,
            'date_signature' => $publicationDate,
            'statut' => 'vigueur',
            'curation_status' => 'published',
            'source_url' => $pdfPath,
        ]);

        if (isset($data['contenu'])) {
            $this->processContentElements($data['contenu'], $document, null);
        }
    }

    private function processContentElements(array $elements, LegalDocument $document, ?StructureNode $parentNode): void
    {
        foreach ($elements as $index => $element) {
            $type = $element['type'] ?? 'Unknown';

            if ($type === 'Article') {
                $this->createArticle($element, $document, $parentNode, $index);
            } else {
                $node = $this->createStructureNode($element, $document, $parentNode, $index);

                if (isset($element['elements'])) {
                    $this->processContentElements($element['elements'], $document, $node);
                }
            }
        }
    }

    private function createStructureNode(array $data, LegalDocument $document, ?StructureNode $parentNode, int $sortOrder): StructureNode
    {
        $nodeId = (string) Str::uuid();
        $safeId = str_replace('-', '_', $nodeId);

        $treePath = $parentNode
            ? $parentNode->tree_path . '.' . $safeId
            : $safeId;

        return StructureNode::create([
            'id' => $nodeId,
            'document_id' => $document->id,
            'type_unite' => $data['type'] ?? 'Section',
            'numero' => $data['numero'] ?? null,
            'titre' => $data['intitule'] ?? null,
            'tree_path' => $treePath,
            'sort_order' => $sortOrder,
            'validation_status' => 'validated',
        ]);
    }

    private function createArticle(array $data, LegalDocument $document, ?StructureNode $parentNode, int $sortOrder): void
    {
        $article = Article::create([
            'document_id' => $document->id,
            'parent_node_id' => $parentNode?->id,
            'numero_article' => $data['numero'] ?? '?',
            'ordre_affichage' => $sortOrder,
            'validation_status' => 'validated',
        ]);

        $content = '';
        if (isset($data['texte']) && is_array($data['texte'])) {
            $paragraphs = array_map(function ($alinea) {
                if (is_array($alinea)) {
                    $text = $alinea['content'] ?? '';
                    if (isset($alinea['type']) && $alinea['type'] === 'enumeration') {
                         return ($alinea['marker'] ?? '-') . ' ' . $text;
                    }
                    return $text;
                }
                return (string) $alinea;
            }, $data['texte']);

            $content = implode("\n\n", $paragraphs);
        }

        ArticleVersion::create([
            'article_id' => $article->id,
            'contenu_texte' => $content,
            'validity_period' => ArticleVersion::makeValidityPeriod($document->date_publication->format('Y-m-d')),
            'validation_status' => 'validated',
        ]);
    }
}
