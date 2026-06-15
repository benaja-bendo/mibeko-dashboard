<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Dossier;
use App\Models\DossierEcheance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'un dossier « affaire » depuis le tableau de bord web.
 *
 * Accepte optionnellement une liste d'échéances (étape « échéances suggérées »
 * du formulaire de création), créées dans la même transaction.
 */
class StoreDossierRequest extends FormRequest
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
            'type' => ['required', Rule::in(Dossier::TYPES)],
            'title' => ['required', 'string', 'max:255'],
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

            'echeances' => ['sometimes', 'array', 'max:50'],
            'echeances.*.type' => ['required', Rule::in(DossierEcheance::TYPES)],
            'echeances.*.title' => ['required', 'string', 'max:255'],
            'echeances.*.due_date' => ['nullable', 'date'],
            'echeances.*.status' => ['sometimes', Rule::in(DossierEcheance::STATUSES)],
            'echeances.*.reminders' => ['sometimes', 'array'],
            'echeances.*.reminders.*' => ['integer', 'min:0', 'max:365'],
            'echeances.*.note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
