<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\StructureNodeResource;
use App\Models\StructureNode;
use Illuminate\Http\JsonResponse;

/**
 * @group Document Structure
 */
class StructureNodeController extends Controller
{
    /**
     * Get document hierarchy.
     *
     * Returns the structure tree for a specific document. This is useful for displaying
     * a table of contents or navigating through the document's divisions (Books, Titles, etc.).
     *
     * @param  string  $documentId  The UUID of the legal document.
     */
    public function tree(string $documentId): JsonResponse
    {
        $nodes = StructureNode::query()
            ->where('document_id', $documentId)
            ->with(['articles.activeVersion'])
            ->orderBy('sort_order')
            ->get();

        return $this->success(
            StructureNodeResource::collection($nodes),
            'Structure récupérée avec succès'
        );
    }
}
