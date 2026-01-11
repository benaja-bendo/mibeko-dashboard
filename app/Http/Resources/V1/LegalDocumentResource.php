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
            'type' => $this->whenLoaded('type', fn () => [
                'code' => $this->type->code,
                'name' => $this->type->nom,
            ]),
            'structure' => StructureNodeResource::collection($this->whenLoaded('structureNodes')),
            'articles' => ArticleSyncResource::collection($this->whenLoaded('articles')),
            'relations' => DocumentRelationResource::collection($this->whenLoaded('relations')),
            'media_files' => $this->whenLoaded('mediaFiles', function () {
                return $this->mediaFiles->map(fn ($file) => [
                    'id' => $file->id,
                    'path' => $file->file_path,
                    'mime_type' => $file->mime_type,
                    'size' => $file->file_size,
                    'description' => $file->description,
                    'created_at' => $file->created_at->toIso8601String(),
                ]);
            }),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
