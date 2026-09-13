<?php

namespace App\Http\Resources\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OnboardingJourneyResource extends JsonResource
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
            'key' => $this->key,
            'version' => $this->version,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'definition' => $this->definition,
            'steps_count' => count($this->definition),
            'enrollments_count' => $this->whenCounted('enrollments'),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'is_editable' => $this->status === 'draft',
            'targeting' => [
                'new_accounts_only' => true,
                'existing_enrollments_keep_version' => true,
            ],
        ];
    }
}
