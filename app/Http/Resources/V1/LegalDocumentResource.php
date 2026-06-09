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

            // Titre — canonical field matches DB column.
            // `title` kept as alias for mobile backward-compatibility.
            'titre_officiel' => $this->titre_officiel,
            'title' => $this->titre_officiel,

            // Référence & Classification
            'reference_nor' => $this->reference_nor,
            'reference' => $this->reference_nor,
            'type_code' => $this->type_code,
            'document_role' => $this->document_role,
            'document_key' => $this->document_key,
            'stock_code' => $this->stock_code,

            // Périmètre juridique (national / ohada / communautaire)
            'legal_scope' => $this->legal_scope ?? 'national',

            // Statuts
            'statut' => $this->statut,
            'status' => $this->statut,
            'curation_status' => $this->curation_status,
            'extraction_status' => $this->extraction_status,

            // Dates (flat pour le SaaS + grouped alias pour le mobile)
            'date_signature' => $this->date_signature?->toDateString(),
            'date_publication' => $this->date_publication?->toDateString(),
            'date_entree_vigueur' => $this->date_entree_vigueur?->toDateString(),
            'consolidation_as_of' => $this->consolidation_as_of?->toDateString(),
            'dates' => [
                'signature' => $this->date_signature?->toIso8601String(),
                'publication' => $this->date_publication?->toIso8601String(),
            ],

            // FK exposées pour les filtres côté client
            'institution_id' => $this->institution_id,
            'official_journal_id' => $this->official_journal_id,

            // Indicateurs de complétude (nécessite withCount dans le contrôleur)
            'articles_count' => $this->whenCounted('articles'),
            'relations_count' => $this->whenCounted('relations'),
            'tags_count' => $this->whenCounted('tags'),
            'missing_stock_code' => $this->document_role === 'STOCK' && empty($this->stock_code),

            // Timestamps
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            // Relations chargées (when loaded)
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
        ];
    }
}
