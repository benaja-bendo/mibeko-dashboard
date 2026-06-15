<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Représentation « ligne d'annuaire » d'un utilisateur (liste admin).
 *
 * Reste légère : les détails coûteux (permissions, abonnement détaillé, audit)
 * vivent dans {@see UserDetailResource}.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Fenêtre de présence : au-delà, l'utilisateur est considéré hors ligne.
     */
    private const ONLINE_WINDOW_MINUTES = 5;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'status' => $this->status ?? 'active',
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()),
            'is_online' => $this->last_seen_at !== null
                && $this->last_seen_at->diffInMinutes(now()) < self::ONLINE_WINDOW_MINUTES,
            'last_seen_at' => optional($this->last_seen_at)->toIso8601String(),
            'two_factor_enabled' => $this->two_factor_secret !== null
                && $this->two_factor_confirmed_at !== null,
            'has_active_subscription' => $this->relationLoaded('subscriptions')
                ? $this->subscriptions->contains(fn ($subscription) => $subscription->valid())
                : $this->subscribed(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)->toIso8601String(),
        ];
    }
}
