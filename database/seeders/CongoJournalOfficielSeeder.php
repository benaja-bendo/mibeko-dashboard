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

class CongoJournalOfficielSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Charger le fichier JSON
        $jsonPath = database_path('data/congo-jo-2025-26.json');
        // Chemin du PDF associé (même nom de base)
        $pdfPath = database_path('data/congo-jo-2025-26.pdf');

        if (! File::exists($jsonPath)) {
            $this->command->error("Le fichier $jsonPath est introuvable.");

            return;
        }

        $jsonData = json_decode(File::get($jsonPath), true);
        $pdfUploadedPath = null;

        // Upload du PDF s'il existe
        if (File::exists($pdfPath)) {
            $this->command->info("Upload du PDF vers MinIO/S3...");
            try {
                // On stocke le fichier avec un nom unique ou basé sur le JSON
                // Ici on utilise un nom simple pour l'exemple, mais l'idéal est un UUID ou hash
                $filename = 'sources/congo-jo-2025-26.pdf';
                
                // Utilisation du disque 's3' configuré pour MinIO
                Storage::disk('s3')->put($filename, File::get($pdfPath));
                $pdfUploadedPath = $filename;
                $this->command->info("PDF uploadé avec succès : $filename");
            } catch (\Exception $e) {
                $this->command->error("Erreur lors de l'upload du PDF : " . $e->getMessage());
            }
        } else {
            $this->command->warn("Le fichier PDF $pdfPath est introuvable. Pas d'upload.");
        }

        // On s'assure qu'il y a un type de document "Loi" par défaut
        $typeLoi = DocumentType::firstOrCreate(
            ['code' => 'LOI'],
            ['nom' => 'Loi', 'niveau_hierarchique' => 40]
        );

        DB::transaction(function () use ($jsonData, $typeLoi, $pdfUploadedPath) {
            foreach ($jsonData['textes'] as $texteData) {
                $this->processLegalDocument($texteData, $typeLoi, $pdfUploadedPath);
            }
        });
    }

    private function processLegalDocument(array $data, DocumentType $type, ?string $pdfPath): void
    {
        $this->command->info('Traitement de : '.$data['numero_texte']);

        // 2. Création du Document Juridique
        $document = LegalDocument::create([
            'type_code' => $type->code,
            // On extrait "Loi n° 10-2025" -> référence NOR simplifiée
            'reference_nor' => Str::slug($data['numero_texte']),
            'titre_officiel' => $data['intitule_long'],
            'date_publication' => $data['date_publication'],
            // Date signature est souvent la même ou proche, à défaut on met publication
            'date_signature' => $data['date_publication'],
            'statut' => 'vigueur',
            'curation_status' => 'published', // On considère que c'est validé
            'source_url' => $pdfPath, // Lien vers le fichier MinIO
        ]);

        // 3. Traitement récursif du contenu (Titres, Chapitres, Articles)
        if (isset($data['contenu'])) {
            $this->processContentElements($data['contenu'], $document, null);
        }
    }

    private function processContentElements(array $elements, LegalDocument $document, ?StructureNode $parentNode): void
    {
        foreach ($elements as $index => $element) {
            $type = $element['type'];

            if ($type === 'Article') {
                $this->createArticle($element, $document, $parentNode, $index);
            } else {
                // C'est un noeud de structure (Titre, Chapitre, etc.)
                $node = $this->createStructureNode($element, $document, $parentNode, $index);

                // Récursion si ce noeud a des enfants
                if (isset($element['elements'])) {
                    $this->processContentElements($element['elements'], $document, $node);
                }
            }
        }
    }

    private function createStructureNode(array $data, LegalDocument $document, ?StructureNode $parentNode, int $sortOrder): StructureNode
    {
        // Création de l'ID manuellement pour pouvoir calculer le tree_path immédiatement
        $nodeId = (string) Str::uuid();

        // Pour Postgres ltree, les UUIDs doivent avoir les tirets remplacés par des underscores
        $safeId = str_replace('-', '_', $nodeId);

        // Calcul du path : si parent existe, on concatène, sinon c'est la racine
        $treePath = $parentNode
            ? $parentNode->tree_path.'.'.$safeId
            : $safeId;

        $node = StructureNode::create([
            'id' => $nodeId,
            'document_id' => $document->id,
            'type_unite' => $data['type'], // ex: "Titre", "Chapitre"
            // Gestion du numéro (ex: "premier", "2", "IV")
            'numero' => $data['numero'] ?? null,
            'titre' => $data['intitule'] ?? null,
            'tree_path' => $treePath,
            'sort_order' => $sortOrder,
            'validation_status' => 'validated',
        ]);

        return $node;
    }

    private function createArticle(array $data, LegalDocument $document, ?StructureNode $parentNode, int $sortOrder): void
    {
        // Création de l'article
        $article = Article::create([
            'document_id' => $document->id,
            'parent_node_id' => $parentNode?->id,
            'numero_article' => $data['numero'],
            'ordre_affichage' => $sortOrder,
            'validation_status' => 'validated',
        ]);

        // Construction du contenu texte à partir des alinéas
        $content = '';
        if (isset($data['texte']) && is_array($data['texte'])) {
            $paragraphs = array_map(function ($alinea) {
                return $alinea['content'] ?? '';
            }, $data['texte']);

            // On joint les paragraphes avec des sauts de ligne HTML ou bruts
            $content = implode("\n\n", $paragraphs);
        }

        // Création de la version de l'article (contenu)
        ArticleVersion::create([
            'article_id' => $article->id,
            'contenu_texte' => $content,
            'validity_period' => ArticleVersion::makeValidityPeriod($document->date_publication ?? now()->format('Y-m-d')),
            'validation_status' => 'validated',
        ]);
    }
}
