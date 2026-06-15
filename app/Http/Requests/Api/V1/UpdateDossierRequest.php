<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Dossier;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mise à jour partielle d'un dossier « affaire » (web). Le type reste
 * modifiable : un dossier conseil peut être requalifié en contentieux.
 */
class UpdateDossierRequest extends FormRequest
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
            'type' => ['sometimes', Rule::in(Dossier::TYPES)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'client' => ['nullable', 'string', 'max:255'],
            'client_role' => ['nullable', Rule::in(['demandeur', 'defendeur'])],
            'adverse' => ['nullable', 'string', 'max:255'],
            'jurisdiction' => ['nullable', 'string', 'max:255'],
            'nature' => ['nullable', 'string', 'max:255'],
            'matiere' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(Dossier::STATUSES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'color' => ['nullable', 'string', 'max:9'],
        ];
    }
}
