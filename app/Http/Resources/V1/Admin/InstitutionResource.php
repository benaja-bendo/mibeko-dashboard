<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstitutionResource extends JsonResource
{
    /**
     * Représentation admin d'une institution émettrice.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Institution $this */
        return [
            'id' => $this->id,
            'name' => $this->nom,
            'acronym' => $this->sigle,
            'documents_count' => $this->whenCounted('legalDocuments'),
        ];
    }
}
