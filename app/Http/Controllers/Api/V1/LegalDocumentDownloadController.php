<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Download
 */
class LegalDocumentDownloadController extends Controller
{
    /**
     * Download legal document data (Flat List).
     *
     * Returns a flat list of structure nodes and articles for offline sync.
     * 
     * @queryParam node_id string Optional. Filter by specific structure node UUID.
     */
    public function download(Request $request, string $id): JsonResponse
    {
        $nodeId = $request->query('node_id');

        // Load document
        $document = LegalDocument::findOrFail($id);

        // Fetch Nodes (Flattened)
        $nodesQuery = $document->structureNodes()->orderBy('sort_order');
        
        if ($nodeId) {
            // Use Ltree logic if available, otherwise simple recursive check or path check
            // Assuming 'path' column exists and is Ltree compatible as per PRD
            // For MVP without strict Ltree class usage, we might depend on database support
            // Here we will implement a basic retrieval. If Ltree is strictly implemented, we'd use mapped queries.
            // For now, let's fetch all and filter in memory if volume is low, or use 'path' like 'Root.Title.%'
            
            // To be safe and compliant with PRD "Ltree logic", we assume there is a 'path' column on structure_nodes
             $nodesQuery->whereRaw("path <@ (SELECT path FROM structure_nodes WHERE id = ?)", [$nodeId]);
        }
        
        $nodes = $nodesQuery->get()->map(function ($node) {
            return [
                'id' => $node->id,
                'type' => $node->type_unite ?? 'SECTION',
                'number' => $node->numero,
                'title' => $node->titre,
                'order' => $node->sort_order,
                // Note: articles are returned separately in flat list format
            ];
        });

        // Fetch Articles (Latest Active Version)
        // We need articles that belong to the fetched nodes
        $nodeIds = $nodes->pluck('id');
        
        $articles = $document->articles()
            ->whereIn('parent_node_id', $nodeIds)
            ->with(['activeVersion', 'tags'])
            ->get()
            ->map(function ($article) use ($document) {
                return [
                    'id' => $article->id,
                    'document_id' => $document->id,
                    'parent_node_id' => $article->parent_node_id,
                    'number' => $article->numero_article ?? '',
                    'order' => $article->ordre_affichage,
                    'content' => $article->activeVersion?->contenu_texte,
                    'tags' => $article->tags->pluck('name')->toArray(),
                    'updated_at' => $article->updated_at->toIso8601String(),
                ];
            });

        return $this->success([
            'resource_id' => $document->id,
            'node_id' => $nodeId,
            'generated_at' => now()->toIso8601String(),
            'nodes' => $nodes,
            'articles' => $articles,
        ], 'Téléchargement préparé avec succès');
    }
}
