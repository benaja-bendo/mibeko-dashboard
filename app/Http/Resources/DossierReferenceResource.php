<?php

namespace App\Http\Resources;

use App\Models\DossierReference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation web d'une référence juridique rattachée à un dossier.
 *
 * `id` expose l'UUID de la cible (`target_id`), conformément au modèle front
 * (`LegalReference.id` = identifiant de l'article/document).
 *
 * @mixin DossierReference
 */
class DossierReferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->target_id,
            'type' => $this->type,
            'title' => $this->title,
            'breadcrumb' => $this->breadcrumb,
            'number' => $this->number,
            'note' => $this->note,
        ];
    }
}
