<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
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
            'number' => $this->numero_article,
            'order' => $this->ordre_affichage,
            'content' => $this->whenLoaded('latestVersion', fn() => $this->latestVersion->contenu_texte),
            'validation_status' => $this->validation_status,
        ];
    }
}
