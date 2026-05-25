<?php

namespace App\Services;

use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentIngestionService
{
    /**
     * Ingeste la structure hiérarchique d'un document (Titres, Chapitres, Articles) dans la base de données.
     */
    public function ingestStructure(LegalDocument $document, array $elements, ?string $parentId, int &$sortOrder, string $extractionId): void
    {
        foreach ($elements as $element) {
            if ($element['type'] === 'Article') {
                // Créer l'article
                $article = $document->articles()->create([
                    'parent_node_id' => $parentId,
                    'numero_article' => $element['numero'],
                    'ordre_affichage' => $sortOrder++,
                ]);

                $validityDate = $document->date_publication ? date('Y-m-d', strtotime($document->date_publication)) : now()->format('Y-m-d');

                // Créer la version
                $article->versions()->create([
                    'contenu_texte' => $element['texte'],
                    'source_extraction_id' => $extractionId,
                    'validity_period' => ArticleVersion::makeValidityPeriod($validityDate),
                ]);
            } else {
                // Créer le nœud (Titre, Chapitre...)
                // Le path LTREE doit être généré. Pour l'instant, on utilise l'ID (remplacé les tirets).
                $nodeId = (string) Str::uuid();
                $pathSegment = str_replace('-', '_', $nodeId);

                $parentPath = '';
                if ($parentId) {
                    $parentNode = DB::table('structure_nodes')->where('id', $parentId)->first();
                    if ($parentNode) {
                        $parentPath = $parentNode->tree_path.'.';
                    }
                }

                $treePath = $parentPath.$pathSegment;

                $node = $document->structureNodes()->make([
                    'type_unite' => $element['type'],
                    'numero' => $element['numero'],
                    'titre' => $element['intitule'] ?? null,
                    'tree_path' => $treePath,
                    'sort_order' => $sortOrder++,
                ]);
                $node->id = $nodeId;
                $node->save();

                if (! empty($element['elements'])) {
                    $this->ingestStructure($document, $element['elements'], $nodeId, $sortOrder, $extractionId);
                }
            }
        }
    }
}
