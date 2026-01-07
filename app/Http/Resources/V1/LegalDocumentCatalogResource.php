<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegalDocumentCatalogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->titre_officiel, // Use correct column name
            'type' => $this->type?->code ?? 'UNKNOWN',
            'version_hash' => md5($this->updated_at->toIso8601String()),
            'last_updated' => $this->updated_at->toIso8601String(),
            'download_size_kb' => 0, // Placeholder
        ];
    }
}
