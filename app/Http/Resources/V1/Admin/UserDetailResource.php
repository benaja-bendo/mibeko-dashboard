<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche détaillée d'un utilisateur pour l'espace admin : profil, sécurité,
 * rôles & permissions (héritées vs directes), usage, abonnement et journal
 * d'audit ciblé.
 *
 * @mixin User
 */
class UserDetailResource extends JsonResource
{
    private const ONLINE_WINDOW_MINUTES = 5;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->subscription();
        $settings = $this->settings;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'email_verified_at' => optional($this->email_verified_at)->toIso8601String(),
            'status' => $this->status ?? 'active',
            'suspended_at' => optional($this->suspended_at)->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,

            // Présence & sécurité
            'is_online' => $this->last_seen_at !== null
                && $this->last_seen_at->diffInMinutes(now()) < self::ONLINE_WINDOW_MINUTES,
            'last_seen_at' => optional($this->last_seen_at)->toIso8601String(),
            'two_factor_enabled' => $this->two_factor_secret !== null
                && $this->two_factor_confirmed_at !== null,
            'active_tokens_count' => $this->tokens()->count(),

            // Rôles & permissions (effectives = héritées des rôles + directes)
            'roles' => $this->getRoleNames()->values(),
            'permissions_direct' => $this->getDirectPermissions()->pluck('name')->values(),
            'permissions_effective' => $this->getAllPermissions()->pluck('name')->values(),

            // Préférences / consentements (RGPD)
            'settings' => $settings ? [
                'locale' => $settings->locale,
                'timezone' => $settings->timezone,
                'marketing_consent' => (bool) $settings->marketing_consent,
                'analytics_consent' => (bool) $settings->analytics_consent,
            ] : null,

            // Usage
            'dossiers_count' => $this->dossiers()->count(),
            'conversations_count' => $this->agentConversations()->count(),

            // Abonnement (Cashier)
            'subscription' => $subscription ? [
                'name' => $subscription->type,
                'stripe_status' => $subscription->stripe_status,
                'ends_at' => optional($subscription->ends_at)->toIso8601String(),
                'on_grace_period' => $subscription->onGracePeriod(),
                'active' => $subscription->valid(),
            ] : null,

            // Journal d'audit ciblé (chargé avec contrainte côté contrôleur)
            'recent_audits' => $this->whenLoaded('audits', fn () => $this->audits->map(fn ($audit) => [
                'id' => $audit->id,
                'event' => $audit->event,
                'tags' => $audit->tags,
                'changed' => array_keys($audit->getModified()),
                'actor_id' => $audit->user_id,
                'actor_name' => optional($audit->user)->name,
                'created_at' => optional($audit->created_at)->toIso8601String(),
            ])->values()),

            'created_at' => optional($this->created_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)->toIso8601String(),
        ];
    }
}
