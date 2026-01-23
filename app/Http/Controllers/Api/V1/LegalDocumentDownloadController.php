<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ArticleSyncResource;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Download
 * 
 * Enpoints related to downloading legal content for offline synchronization.
 */
class LegalDocumentDownloadController extends Controller
{
    /**
     * Download legal document data (Flat List).
     *
     * Returns a flat list of structure nodes and articles for offline sync. 
     * This is optimized for the mobile application's local database insertion.
     * 
     * @param string $id The UUID of the legal document.
     * @queryParam node_id string Optional. Filter by specific structure node UUID to download only a sub-tree.
     * 
     * @response 200 {
     *  "success": true,
     *  "message": "Téléchargement préparé avec succès",
     *  "data": {
     *    "resource_id": "uuid",
     *    "node_id": "uuid-optional",
     *    "generated_at": "2026-01-23T10:00:00Z",
     *    "nodes": [
     *      { "id": "uuid", "type": "TITRE", "number": "I", "title": "Nom du titre", "order": 1 }
     *    ],
     *    "articles": [
     *      { "id": "uuid", "document_id": "uuid", "parent_node_id": "uuid", "number": "1", "order": 1, "content": "Texte...", "tags": ["loi", "congo"], "updated_at": "2026-01-23T10:00:00Z" }
     *    ]
     *  }
     * }
     */
    public function download(Request $request, string $id): JsonResponse
    {
        $nodeId = $request->query('node_id');

        // Load document
        $document = LegalDocument::findOrFail($id);

        // Fetch Nodes (Flattened)
        $nodesQuery = $document->structureNodes()->orderBy('sort_order');
        
        if ($nodeId) {
             $nodesQuery->whereRaw("path <@ (SELECT path FROM structure_nodes WHERE id = ?)", [$nodeId]);
        }
        
        $nodes = $nodesQuery->get()->map(function ($node) {
            return [
                'id' => $node->id,
                'type' => $node->type_unite ?? 'SECTION',
                'number' => $node->numero,
                'title' => $node->titre,
                'order' => $node->sort_order,
            ];
        });

        // Fetch Articles (Latest Active Version)
        $nodeIds = $nodes->pluck('id');
        
        $articles = $document->articles()
            ->whereIn('parent_node_id', $nodeIds)
            ->with(['activeVersion', 'tags'])
            ->get();

        return $this->success([
            'resource_id' => $document->id,
            'node_id' => $nodeId,
            'generated_at' => now()->toIso8601String(),
            'nodes' => $nodes,
            'articles' => ArticleSyncResource::collection($articles),
        ], 'Téléchargement préparé avec succès');
    }
}
