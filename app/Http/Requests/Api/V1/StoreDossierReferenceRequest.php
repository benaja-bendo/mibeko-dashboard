<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DossierReference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajout d'une référence juridique (article/document) à un dossier (web).
 *
 * `id` est l'UUID de la cible côté base juridique (LegalReference.id) ; l'ajout
 * répété d'une même cible est idempotent (cf. contrôleur).
 */
class StoreDossierReferenceRequest extends FormRequest
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
            'id' => ['required', 'uuid'],
            'type' => ['required', Rule::in(DossierReference::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'breadcrumb' => ['nullable', 'string', 'max:1000'],
            'number' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
