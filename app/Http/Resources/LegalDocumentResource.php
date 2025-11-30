<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegalDocumentResource extends JsonResource
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
            'type' => [
                'code' => $this->type_code,
                'nom' => $this->type?->nom,
                'niveau_hierarchique' => $this->type?->niveau_hierarchique,
            ],
            'institution' => [
                'id' => $this->institution_id,
                'nom' => $this->institution?->nom,
                'sigle' => $this->institution?->sigle,
            ],
            'titre_officiel' => $this->titre_officiel,
            'reference_nor' => $this->reference_nor,
            'dates' => [
                'signature' => $this->date_signature?->format('Y-m-d'),
                'publication' => $this->date_publication?->format('Y-m-d'),
                'entree_vigueur' => $this->date_entree_vigueur?->format('Y-m-d'),
            ],
            'source_url' => $this->source_url,
            'statut' => $this->statut,
            'structure_nodes' => $this->whenLoaded('structureNodes', function () {
                return $this->structureNodes->map(function ($node) {
                    return [
                        'id' => $node->id,
                        'type_unite' => $node->type_unite,
                        'numero' => $node->numero,
                        'titre' => $node->titre,
                        'tree_path' => $node->tree_path,
                    ];
                });
            }),
            'articles' => ArticleResource::collection($this->whenLoaded('articles')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
