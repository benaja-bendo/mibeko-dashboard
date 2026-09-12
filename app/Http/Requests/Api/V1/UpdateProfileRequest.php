<?php

namespace App\Http\Requests\Api\V1;

use App\Models\MobileProfile;
use App\Rules\InternationalPhoneNumber;
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
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', new InternationalPhoneNumber],
            // mibeko-dashboard#98 : liste fermée depuis la normalisation du
            // 06/09/2026 — un texte libre a produit cinq orthographes
            // d'« étudiant » et des réponses hors catégorie, invisibles à
            // toute mesure de segmentation. Conservée telle quelle pour les
            // anciennes apps mobiles déjà distribuées (mibeko-dashboard#135).
            'profession' => ['sometimes', 'nullable', Rule::in(MobileProfile::PROFESSIONS)],
            // mibeko-dashboard#135 : codes stables non traduits, successeurs
            // de `profession` en tant que catégorie, partagés par web/mobile.
            'usage_context' => ['sometimes', 'nullable', Rule::in(MobileProfile::USAGE_CONTEXTS)],
            // Métier facultatif, texte libre — distinct de `profession` (une
            // catégorie n'implique pas un métier précis).
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Centres d'intérêt : slugs de la taxonomie "Thèmes de vie" déjà
            // existante (table `tags`), réutilisée plutôt que dupliquée.
            'interests' => ['sometimes', 'array'],
            'interests.*' => ['string', 'distinct', Rule::exists('tags', 'slug')],
        ];
    }
}
