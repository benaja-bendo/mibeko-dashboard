<?php

namespace App\Http\Requests\Api\V1;

use App\Models\UserSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valide la matrice de préférences de notification (canal × type × fréquence).
 *
 * Les règles sont générées à partir des constantes du modèle pour rester
 * synchronisées avec les types/canaux supportés.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'preferences' => ['required', 'array'],
            'preferences._frequency' => ['required', Rule::in(UserSetting::NOTIFICATION_FREQUENCIES)],
        ];

        foreach (UserSetting::NOTIFICATION_TYPES as $type) {
            $rules["preferences.{$type}"] = ['required', 'array'];

            foreach (UserSetting::NOTIFICATION_CHANNELS as $channel) {
                $rules["preferences.{$type}.{$channel}"] = ['required', 'boolean'];
            }
        }

        return $rules;
    }
}
