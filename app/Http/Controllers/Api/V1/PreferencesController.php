<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateConsentsRequest;
use App\Http\Requests\Api\V1\UpdateNotificationPreferencesRequest;
use App\Http\Requests\Api\V1\UpdatePreferencesRequest;
use App\Http\Resources\V1\UserSettingResource;
use App\Traits\HttpResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group User Preferences
 *
 * Préférences d'affichage, préférences de notification et consentements RGPD.
 * Toutes les écritures sur `user_settings` sont tracées via owen-it/laravel-auditing.
 */
class PreferencesController extends Controller
{
    use HttpResponses;

    /**
     * Retourne l'ensemble des préférences (localisation + notifications + consentements).
     */
    public function show(Request $request): JsonResponse
    {
        $settings = $request->user()->settingsOrCreate();

        return $this->success(new UserSettingResource($settings), 'Préférences récupérées.');
    }

    /**
     * Met à jour les préférences d'affichage (langue, fuseau, format de date).
     */
    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $settings = $request->user()->settingsOrCreate();
        $settings->update($request->validated());

        return $this->success(new UserSettingResource($settings->fresh()), 'Préférences mises à jour.');
    }

    /**
     * Remplace la matrice de préférences de notification (canal × type × fréquence).
     */
    public function updateNotifications(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $settings = $request->user()->settingsOrCreate();
        $settings->update(['notification_preferences' => $request->validated()['preferences']]);

        return $this->success(new UserSettingResource($settings->fresh()), 'Préférences de notification mises à jour.');
    }

    /**
     * Met à jour les consentements RGPD en horodatant chaque bascule.
     */
    public function updateConsents(UpdateConsentsRequest $request): JsonResponse
    {
        $settings = $request->user()->settingsOrCreate();
        $validated = $request->validated();
        $payload = [];

        if (array_key_exists('marketing', $validated)) {
            $payload['marketing_consent'] = $validated['marketing'];
            $payload['marketing_consent_at'] = $validated['marketing'] ? now() : null;
        }

        if (array_key_exists('analytics', $validated)) {
            $payload['analytics_consent'] = $validated['analytics'];
            $payload['analytics_consent_at'] = $validated['analytics'] ? now() : null;
        }

        if ($payload !== []) {
            $settings->update($payload);
        }

        return $this->success(new UserSettingResource($settings->fresh()), 'Consentements mis à jour.');
    }
}
