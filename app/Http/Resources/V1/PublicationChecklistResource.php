<?php

namespace App\Http\Resources\V1;

use App\Models\PublicationChecklist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Preuve de validation (dashboard#119) : un passage du garde-fou de
 * publication, avec l'acteur, le résultat et l'instantané des critères
 * vérifiés — de quoi répondre après coup à « qui a validé quoi, et sur
 * quelle version du document ».
 */
class PublicationChecklistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PublicationChecklist $this */
        return [
            'id' => $this->id,
            'target_status' => $this->target_status,
            'outcome' => $this->outcome,
            'criteria' => $this->criteria,
            'document_snapshot_updated_at' => $this->document_snapshot_updated_at?->toIso8601String(),
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
