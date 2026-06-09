<?php

namespace App\Http\Requests\Api\V1;

use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valide les préférences d'affichage / localisation de l'utilisateur.
 */
class UpdatePreferencesRequest extends FormRequest
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
            // Langues actuellement supportées par l'interface.
            'locale' => ['sometimes', 'required', 'string', Rule::in(['fr', 'en'])],
            // Fuseau IANA valide (liste système).
            'timezone' => ['sometimes', 'required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            // Formats de date proposés dans l'UI.
            'date_format' => ['sometimes', 'required', 'string', Rule::in(['d/m/Y', 'Y-m-d', 'd M Y'])],
        ];
    }
}
