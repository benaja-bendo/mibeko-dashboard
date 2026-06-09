<?php

namespace App\Http\Resources\V1;

use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialise les préférences applicatives et consentements d'un utilisateur.
 *
 * @mixin UserSetting
 */
class UserSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'date_format' => $this->date_format,
            // On garantit une matrice complète même si la colonne est nulle.
            'notification_preferences' => $this->notification_preferences
                ?? UserSetting::defaultNotificationPreferences(),
            'consents' => [
                'marketing' => (bool) $this->marketing_consent,
                'marketing_at' => $this->marketing_consent_at?->toIso8601String(),
                'analytics' => (bool) $this->analytics_consent,
                'analytics_at' => $this->analytics_consent_at?->toIso8601String(),
            ],
        ];
    }
}
