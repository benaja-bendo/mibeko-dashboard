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
            'content' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion->contenu_texte),
            'document_id' => $this->document_id,
            'document_title' => $this->document?->titre_officiel,
            'document_type' => $this->document?->type?->code,
            'node_title' => $this->parentNode?->titre ?? '',
            'breadcrumb' => $this->breadcrumb,
            'validation_status' => $this->validation_status,
        ];
    }
}
