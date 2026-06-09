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
            'status' => $this->status,
            // Profil étendu (téléphone, fonction, organisation) — entité unique côté DRC.
            'profile' => [
                'phone' => $profile?->phone,
                'profession' => $profile?->profession,
                'company' => $profile?->company,
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
