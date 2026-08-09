<?php

namespace App\Http\Resources\V1;

use App\Models\Article;
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
        /** @var Article $this */

        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'parent_node_id' => $this->parent_node_id,
            'number' => $this->numero_article,
            'order' => $this->ordre_affichage,
            'content' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion->contenu_texte),

            // Structure d'un tableau, quand l'article en porte un. Sans elle, un
            // corpus hors-ligne n'a que la forme linéarisée (« A | B | C ») et ne
            // peut plus rendre de vrai tableau : le texte reste lisible, mais la
            // colonne se perd. Le HTML d'origine n'est jamais transmis — c'est de
            // la provenance, elle reste côté pipeline.
            'content_format' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->contentFormat()),
            'tables' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->publicTables() ?? []),

            // Signaux de confiance (borne basse de validité + relecture juriste),
            // exposés quand la version active est chargée. `reviewed_at` est null
            // tant qu'aucun juriste n'a relu : le client n'affiche la mention de
            // vérification QUE si non-null (jamais de garantie inventée).
            'validity_start' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->validity_start),
            'reviewed_at' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->reviewed_at?->toIso8601String()),

            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')), // or full tag objects if needed. PRD "Semantic Tagging" implies using string names for search.
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
