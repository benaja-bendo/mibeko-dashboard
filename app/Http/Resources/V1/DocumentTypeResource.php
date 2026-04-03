<?php

namespace App\Http\Resources\V1;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
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
        ];
    }
}
