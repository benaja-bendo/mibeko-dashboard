<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeResource extends JsonResource
{
    /**
     * Représentation admin d'un type de document (« type de loi »).
     *
     * Expose le compteur d'usage (`documents_count`) afin que l'interface
     * d'administration affiche l'impact et désactive la suppression d'un
     * référentiel encore référencé par des documents.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DocumentType $this */
        return [
            'code' => $this->code,
            'name' => $this->nom,
            'hierarchy_level' => $this->niveau_hierarchique,
            'documents_count' => $this->whenCounted('legalDocuments'),
        ];
    }
}
