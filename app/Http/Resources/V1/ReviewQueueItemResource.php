<?php

namespace App\Http\Resources\V1;

use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de la file de revue (mibeko-front#33) : de quoi trier par priorité,
 * afficher l'ancienneté, le responsable et le motif de blocage sans recharger
 * le document complet.
 */
class ReviewQueueItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LegalDocument $this */
        return [
            'id' => $this->id,
            'titre_officiel' => $this->titre_officiel,
            'libelle_descriptif' => $this->libelle_descriptif,
            'type' => $this->whenLoaded('type', fn () => [
                'code' => $this->type->code,
                'name' => $this->type->nom,
            ]),
            'curation_status' => $this->curation_status,
            'curation_status_changed_at' => $this->curation_status_changed_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ] : null),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'blocking_flags_count' => (int) $this->blocking_flags_count,
            'warning_flags_count' => (int) $this->warning_flags_count,
        ];
    }
}
