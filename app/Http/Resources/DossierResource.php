<?php

namespace App\Http\Resources;

use App\Models\Dossier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation « affaire » d'un dossier pour le tableau de bord web.
 *
 * Mappe les colonnes de stockage (partagées avec la sync mobile) vers le
 * vocabulaire métier du web : `name` → objet du litige, `legal_domain` →
 * matière. Les échéances ne sont exposées que lorsqu'elles sont chargées.
 *
 * @mixin Dossier
 */
class DossierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->name,
            'reference' => $this->internal_reference,
            'client' => $this->client_name,
            'client_role' => $this->client_role,
            'adverse' => $this->adverse_party,
            'jurisdiction' => $this->jurisdiction,
            'nature' => $this->nature,
            'matiere' => $this->legal_domain,
            'status' => $this->status,
            'description' => $this->description,
            'color' => $this->color,
            'echeances' => DossierEcheanceResource::collection($this->whenLoaded('echeances')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
