<?php

namespace App\Http\Resources\V1;

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
        /** @var \App\Models\LegalDocument $this */
        return [
            'id' => $this->id,
            'title' => $this->titre_officiel,
            'reference' => $this->reference_nor,
            'status' => $this->statut,
            'dates' => [
                'signature' => $this->date_signature?->toIso8601String(),
                'publication' => $this->date_publication?->toIso8601String(),
            ],
            'institution' => InstitutionResource::make($this->whenLoaded('institution')),
            'type' => $this->whenLoaded('type', fn() => [
                'code' => $this->type->code,
                'name' => $this->type->nom,
            ]),
            'articles' => ArticleResource::collection($this->whenLoaded('articles')),
        ];
    }
}
