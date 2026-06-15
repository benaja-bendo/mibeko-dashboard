<?php

namespace App\Http\Resources;

use App\Models\DossierEcheance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DossierEcheance
 */
class DossierEcheanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dossier_id' => $this->dossier_id,
            'type' => $this->type,
            'title' => $this->title,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status,
            'trigger_event' => $this->trigger_event,
            'trigger_date' => $this->trigger_date?->format('Y-m-d'),
            'rule_id' => $this->rule_id,
            'basis_article_id' => $this->basis_article_id,
            'is_confirmed' => $this->is_confirmed,
            'reminders' => $this->reminders ?? [],
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
