<?php

namespace App\Http\Resources;

use App\Models\DossierGeneratedDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DossierGeneratedDocument
 */
class DossierGeneratedDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'template_name' => $this->template_name,
            'title' => $this->title,
            'html' => $this->html,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
