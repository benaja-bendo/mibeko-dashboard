<?php

namespace App\Http\Resources\V1;

use App\Models\StructureNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StructureNodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Extracts parent_id from the ltree tree_path for mobile hierarchy.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StructureNode $this */
        $parts = explode('.', $this->tree_path);
        $parentId = null;
        if (count($parts) > 1) {
            $parentId = str_replace('_', '-', $parts[count($parts) - 2]);
        }

        return [
            'id' => $this->id,
            'parent_id' => $parentId,
            'type' => $this->type_unite,
            'number' => $this->numero,
            'title' => $this->titre,
            'order' => $this->sort_order,
            'articles' => ArticleBriefResource::collection($this->whenLoaded('articles')),
        ];
    }
}
