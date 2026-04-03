<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Support\Str;

class DocumentImportService
{
    /**
     * Parse structured JSON content and import it into the LegalDocument.
     *
     * @param  array  $data  The decoded JSON data
     */
    public function importContent(LegalDocument $document, array $data): void
    {
        if (isset($data['structure'])) {
            $this->processStructureNodes($data['structure'], $document, null);
        } elseif (isset($data['contenu'])) {
            $this->processContentElements($data['contenu'], $document, null);
        } elseif (isset($data['textes']) && is_array($data['textes'])) {
            foreach ($data['textes'] as $index => $texte) {
                // Determine the type from numero_texte if possible
                $type = 'Texte';
                if (!empty($texte['numero_texte'])) {
                    $parts = explode(' ', $texte['numero_texte']);
                    if (count($parts) > 0) {
                        $type = $parts[0];
                    }
                }

                // Create a wrapper node for the text itself
                $node = $this->createStructureNode([
                    'type' => $type,
                    'numero' => $texte['numero_texte'] ?? null,
                    'intitule' => $texte['intitule_long'] ?? null,
                ], $document, null, $index);

                if (isset($texte['contenu']) && is_array($texte['contenu'])) {
                    $this->processContentElements($texte['contenu'], $document, $node);
                }
            }
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
            if (! empty($nodeData['children'])) {
                $this->processStructureNodes($nodeData['children'], $document, $node);
            }
        }
    }

    private function createStructureNodeFromSchema2(array $data, LegalDocument $document, ?StructureNode $parentNode, int $sortOrder): StructureNode
    {
        $nodeId = (string) Str::uuid();
        $safeId = str_replace('-', '_', $nodeId);

        $treePath = $parentNode
            ? $parentNode->tree_path.'.'.$safeId
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

        $validityDate = $document->date_publication ? $document->date_publication->format('Y-m-d') : now()->format('Y-m-d');

        ArticleVersion::create([
            'article_id' => $article->id,
            'contenu_texte' => $content,
            'validity_period' => ArticleVersion::makeValidityPeriod($validityDate),
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
            ? $parentNode->tree_path.'.'.$safeId
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
                        return ($alinea['marker'] ?? '-').' '.$text;
                    }

                    return $text;
                }

                return (string) $alinea;
            }, $data['texte']);

            $content = implode("\n\n", $paragraphs);
        }

        $validityDate = $document->date_publication ? $document->date_publication->format('Y-m-d') : now()->format('Y-m-d');

        ArticleVersion::create([
            'article_id' => $article->id,
            'contenu_texte' => $content,
            'validity_period' => ArticleVersion::makeValidityPeriod($validityDate),
            'validation_status' => 'validated',
        ]);
    }
}
