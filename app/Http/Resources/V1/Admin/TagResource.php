<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TagResource extends JsonResource
{
    /**
     * Représentation admin d'un tag (taxonomie transverse).
     *
     * `usage_count` agrège documents + articles : c'est lui qui conditionne
     * la suppression bloquante côté contrôleur et l'affichage côté UI.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Tag $this */
        $documents = $this->whenCounted('legalDocuments', $this->legal_documents_count ?? 0);
        $articles = $this->whenCounted('articles', $this->articles_count ?? 0);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'documents_count' => $documents,
            'articles_count' => $articles,
            'usage_count' => (int) ($this->legal_documents_count ?? 0) + (int) ($this->articles_count ?? 0),
        ];
    }
}
