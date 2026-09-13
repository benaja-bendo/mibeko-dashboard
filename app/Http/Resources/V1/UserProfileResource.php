<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Charge utile complète du compte : identité, profil étendu, rôles/permissions
 * (lecture seule côté client) et préférences applicatives.
 *
 * @mixin User
 */
class UserProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->mobileProfile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'email_verification_required' => $this->email_verification_required,
            // Timestamp exposé en plus du booléen (l'app mobile en a besoin pour
            // l'écran de vérification) — ajout non destructif.
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'status' => $this->status,
            // Profil étendu (téléphone, cadre d'usage, métier, organisation,
            // centres d'intérêt) — entité unique côté DB.
            'profile' => [
                'phone' => $profile?->phone,
                'profession' => $profile?->profession,
                'usage_context' => $profile?->usage_context,
                'job_title' => $profile?->job_title,
                'company' => $profile?->company,
                // Slugs de la taxonomie "Thèmes de vie" (table `tags`).
                'interests' => $this->tags->sortBy('display_order')->pluck('slug')->values(),
            ],
            // RBAC en lecture seule : l'utilisateur ne peut pas modifier ses propres rôles.
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            // Indicateurs de sécurité utiles à l'écran « Compte ».
            'security' => [
                'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
                'two_factor_confirmed' => $this->two_factor_confirmed_at !== null,
            ],
            'settings' => new UserSettingResource($this->settingsOrCreate()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
