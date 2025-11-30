<?php

namespace App\Http\Resources;

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
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'numero_article' => $this->numero_article,
            'ordre_affichage' => $this->ordre_affichage,
            'parent_node' => $this->whenLoaded('parentNode', function () {
                return [
                    'id' => $this->parentNode->id,
                    'type_unite' => $this->parentNode->type_unite,
                    'numero' => $this->parentNode->numero,
                    'titre' => $this->parentNode->titre,
                ];
            }),
            'versions' => $this->whenLoaded('versions', function () {
                return $this->versions->map(function ($version) {
                    return [
                        'id' => $version->id,
                        'contenu_texte' => $version->contenu_texte,
                        'valid_from' => $version->valid_from?->format('Y-m-d'),
                        'valid_until' => $version->valid_until?->format('Y-m-d'),
                        'is_current' => $version->valid_until === null,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
