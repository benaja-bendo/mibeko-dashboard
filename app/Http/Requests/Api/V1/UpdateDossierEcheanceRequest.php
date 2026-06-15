<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DossierEcheance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mise à jour partielle d'une échéance (web).
 */
class UpdateDossierEcheanceRequest extends FormRequest
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
            'type' => ['sometimes', Rule::in(DossierEcheance::TYPES)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(DossierEcheance::STATUSES)],
            'trigger_event' => ['nullable', 'string', 'max:255'],
            'trigger_date' => ['nullable', 'date'],
            'rule_id' => ['nullable', 'string', 'max:255'],
            'basis_article_id' => ['nullable', 'uuid', 'exists:articles,id'],
            'is_confirmed' => ['sometimes', 'boolean'],
            'reminders' => ['sometimes', 'array'],
            'reminders.*' => ['integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
