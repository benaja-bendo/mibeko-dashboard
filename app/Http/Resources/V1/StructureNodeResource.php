<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StructureNodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\StructureNode $this */
        return [
            'id' => $this->id,
            'type' => $this->type_unite,
            'number' => $this->numero,
            'title' => $this->titre,
            'order' => $this->sort_order,
            'articles' => ArticleBriefResource::collection($this->whenLoaded('articles')),
        ];
    }
}
