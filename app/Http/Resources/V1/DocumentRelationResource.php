<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRelationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\DocumentRelation $this */
        return [
            'id' => $this->id,
            'source_document_id' => $this->source_doc_id,
            'target_document_id' => $this->target_doc_id,
            'relation_type' => $this->relation_type,
            'comment' => $this->commentaire,
            'target_document' => LegalDocumentResource::make($this->whenLoaded('targetDocument')),
        ];
    }
}
