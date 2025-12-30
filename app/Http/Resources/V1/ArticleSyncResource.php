<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleSyncResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Article $this */

        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'parent_node_id' => $this->parent_node_id,
            'number' => $this->numero_article,
            'order' => $this->ordre_affichage,
            'content' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion->contenu_texte),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')), // or full tag objects if needed. PRD "Semantic Tagging" implies using string names for search.
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
