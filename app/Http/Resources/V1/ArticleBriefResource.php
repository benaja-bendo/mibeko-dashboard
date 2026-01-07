<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Simplified article resource for tree/structure endpoints.
 * Matches mobile's RemoteArticleBrief model.
 */
class ArticleBriefResource extends JsonResource
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
            'number' => $this->numero_article ?? '',
            'order' => $this->ordre_affichage ?? 0,
            'content' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->contenu_texte),
            'validation_status' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->validation_status ?? 'validated', 'validated'),
        ];
    }
}
