<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valide la mise à jour des consentements RGPD (opt-in/opt-out).
 *
 * Chaque consentement est optionnel dans la requête : seuls les champs présents
 * sont mis à jour, ce qui permet des bascules indépendantes côté UI.
 */
class UpdateConsentsRequest extends FormRequest
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
        return [
            'marketing' => ['sometimes', 'boolean'],
            'analytics' => ['sometimes', 'boolean'],
        ];
    }
}
