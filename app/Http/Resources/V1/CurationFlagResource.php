<?php

namespace App\Http\Resources\V1;

use App\Models\CurationFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation éditeur d'une anomalie de curation pour la vue Contrôle :
 * de quoi afficher le détail, naviguer vers la cible dans l'arbre/le PDF, et
 * proposer une correction.
 */
class CurationFlagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CurationFlag $this */
        return [
            'id' => $this->id,
            'source' => $this->source,
            'type_probleme' => $this->type_probleme,
            'severity' => $this->severity,
            'description' => $this->description,
            'suggestion' => $this->suggestion,
            'anchor' => $this->anchor,
            'confidence' => $this->confidence,
            'resolved' => (bool) $this->resolved,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            // Cible dans l'arbre : un article OU une division (node), avec le numéro
            // de page pour le surlignage PDF si connu.
            'article_id' => $this->article_id,
            'node_id' => $this->node_id,
            'page' => $this->anchor['page'] ?? null,
        ];
    }
}
