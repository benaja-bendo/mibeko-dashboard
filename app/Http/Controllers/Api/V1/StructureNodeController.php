<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Http\Resources\V1\StructureNodeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @group Document Structure
 */
class StructureNodeController extends Controller
{
    /**
     * Get document hierarchy.
     * 
     * Returns the structure tree for a specific document.
     */
    public function tree(string $documentId): \Illuminate\Http\JsonResponse
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
