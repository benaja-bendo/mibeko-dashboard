<?php

namespace App\Http\Resources\V1;

use App\Models\LegalDocument;
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
        /** @var LegalDocument $this */
        return [
            'id' => $this->id,
            'official_journal_id' => $this->official_journal_id,
            'title' => $this->titre_officiel,
            'reference' => $this->reference_nor,
            'status' => $this->statut,
            'dates' => [
                'signature' => $this->date_signature?->toIso8601String(),
                'publication' => $this->date_publication?->toIso8601String(),
            ],
            'institution' => InstitutionResource::make($this->whenLoaded('institution')),
            'official_journal' => $this->whenLoaded('officialJournal', fn () => [
                'id' => $this->officialJournal->id,
                'title' => $this->officialJournal->title,
                'publication_date' => $this->officialJournal->publication_date?->toIso8601String(),
            ]),
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
