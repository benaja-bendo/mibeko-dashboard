<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\UserInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation admin d'une invitation d'équipe.
 *
 * @mixin UserInvitation
 */
class UserInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'roles' => $this->roles ?? [],
            'status' => $this->statusLabel(),
            'invited_by' => $this->whenLoaded('inviter', fn () => optional($this->inviter)->name),
            'expires_at' => optional($this->expires_at)->toIso8601String(),
            'accepted_at' => optional($this->accepted_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Statut dérivé : accepted | expired | pending.
     */
    private function statusLabel(): string
    {
        if ($this->accepted_at !== null) {
            return 'accepted';
        }

        return $this->expires_at !== null && $this->expires_at->isPast() ? 'expired' : 'pending';
    }
}
