<?php

namespace App\Http\Resources;

use App\Models\DossierPiece;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DossierPiece
 */
class DossierPieceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'mime' => $this->mime,
            'note' => $this->note,
            'added_at' => $this->added_at?->toISOString(),
        ];
    }
}
