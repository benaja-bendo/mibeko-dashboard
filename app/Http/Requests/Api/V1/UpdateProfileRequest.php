<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valide la mise à jour des informations personnelles du compte.
 *
 * L'email n'est volontairement pas modifiable ici : un changement d'email
 * nécessiterait un flux de re-vérification dédié (hors périmètre de cet écran).
 */
class UpdateProfileRequest extends FormRequest
{
    /**
     * L'autorisation est portée par le middleware auth:sanctum ; tout utilisateur
     * authentifié peut modifier son propre profil.
     */
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'profession' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
