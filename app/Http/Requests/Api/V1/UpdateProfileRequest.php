<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MobileProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // mibeko-dashboard#98 : liste fermée depuis la normalisation du
            // 06/09/2026 — un texte libre a produit cinq orthographes
            // d'« étudiant » et des réponses hors catégorie, invisibles à
            // toute mesure de segmentation.
            'profession' => ['sometimes', 'nullable', Rule::in(MobileProfile::PROFESSIONS)],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
