<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Observers\ArticleVersionObserver;
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
        // Disable automatic embedding generation during seeding
        ArticleVersionObserver::$shouldSkipEmbeddings = true;

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

            foreach ($textes as $index => $texteData) {
                $refNor = $texteData['reference_nor']
                    ?? (isset($texteData['numero_texte']) ? Str::slug($texteData['numero_texte']) : null);

                if (!$refNor) {
                    // Fallback: Use filename. If multiple texts in file, append index.
                    $refNor = Str::slug($baseName);
                    if (count($textes) > 1) {
                        $refNor .= '-' . ($index + 1);
                    }
                }

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

            // Check if file already exists in S3 to avoid re-uploading every time
            if (Storage::disk('s3')->exists($filename)) {
                return $filename;
            }

            Storage::disk('s3')->put($filename, File::get($localPath));
            return $filename;
        } catch (\Exception $e) {
            $this->command->error("Erreur lors de l'upload PDF pour {$baseName}: " . $e->getMessage());
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
        // 1. Determine Type Code
        $typeCode = $data['type_code'] ?? 'LOI';

        if (!isset($data['type_code'])) {
            $num = strtolower($data['numero_texte'] ?? '');
            if (str_contains($num, 'décret')) $typeCode = 'DEC';
            if (str_contains($num, 'arrêté')) $typeCode = 'ARR';
            if (str_contains($num, 'constitution')) $typeCode = 'CONST';
            if (str_contains($num, 'ordonnance')) $typeCode = 'ORD';
        }

        $publicationDate = $this->parseSafeDate($data['date_publication'] ?? $data['date_signature'] ?? null);

        // 2. Determine Title
        $title = $data['titre_officiel'] ?? $data['intitule_long'] ?? 'Document sans titre';

        // 3. Create Document
        $document = LegalDocument::create([
            'type_code' => $typeCode,
            'reference_nor' => $refNor,
            'titre_officiel' => $title,
            'date_publication' => $publicationDate,
            'date_signature' => $this->parseSafeDate($data['date_signature'] ?? null),
            'statut' => 'vigueur',
            'curation_status' => 'published',
        ]);

        if ($pdfPath) {
            $document->mediaFiles()->create([
                'file_path' => $pdfPath,
                'mime_type' => 'application/pdf',
                'description' => 'Original signé',
            ]);
        }

        // 4. Process Content
        if (isset($data['structure'])) {
            $this->processStructureNodes($data['structure'], $document, null);
        } elseif (isset($data['contenu'])) {
            $this->processContentElements($data['contenu'], $document, null);
        }
    }

    private function processStructureNodes(array $nodes, LegalDocument $document, ?StructureNode $parentNode): void
    {
        foreach ($nodes as $index => $nodeData) {
            // Create StructureNode
            $node = $this->createStructureNodeFromSchema2($nodeData, $document, $parentNode, $index);

            // Process Articles inside this structure node
            if (isset($nodeData['articles']) && is_array($nodeData['articles'])) {
                foreach ($nodeData['articles'] as $artIndex => $articleData) {
                    $this->createArticleFromSchema2($articleData, $document, $node, $artIndex);
                }
            }

            // Process Children (nested structure nodes)
            if (!empty($nodeData['children'])) {
                $this->processStructureNodes($nodeData['children'], $document, $node);
            }
        }
    }

    private function createStructureNodeFromSchema2(array $data, LegalDocument $document, ?StructureNode $parentNode, int $sortOrder): StructureNode
    {
        $nodeId = (string) Str::uuid();
        $safeId = str_replace('-', '_', $nodeId);

        $treePath = $parentNode
            ? $parentNode->tree_path . '.' . $safeId
            : $safeId;

        return StructureNode::create([
            'id' => $nodeId,
            'document_id' => $document->id,
            'type_unite' => $data['type_unite'] ?? 'Section',
            'numero' => $data['numero'] ?? null,
            'titre' => $data['titre'] ?? null,
            'tree_path' => $treePath,
            'sort_order' => $sortOrder,
            'validation_status' => 'validated',
        ]);
    }

    private function createArticleFromSchema2(array $data, LegalDocument $document, ?StructureNode $parentNode, int $sortOrder): void
    {
        $article = Article::create([
            'document_id' => $document->id,
            'parent_node_id' => $parentNode?->id,
            'numero_article' => $data['numero'] ?? '?',
            'ordre_affichage' => $sortOrder,
            'validation_status' => 'validated',
        ]);

        $content = $data['contenu'] ?? '';

        ArticleVersion::create([
            'article_id' => $article->id,
            'contenu_texte' => $content,
            'validity_period' => ArticleVersion::makeValidityPeriod($document->date_publication->format('Y-m-d')),
            'validation_status' => 'validated',
        ]);
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
