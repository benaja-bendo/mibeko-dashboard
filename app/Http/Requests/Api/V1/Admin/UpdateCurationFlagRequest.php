<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\CurationFlag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCurationFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * L'état de résolution est modifiable côté triage (résoudre / ré-ouvrir).
     * La `severity` est aussi requalifiable : un signalement public (`source`
     * = report, toujours créé en `info`) peut être escaladé en `blocking` si
     * l'admin confirme au triage qu'il décrit un vrai problème — et
     * inversement rétrogradé si le signalement s'avère mineur. Le reste du
     * contenu reste tel qu'émis par l'auteur.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resolved' => ['required', 'boolean'],
            'severity' => ['sometimes', 'string', Rule::in([
                CurationFlag::SEVERITY_BLOCKING,
                CurationFlag::SEVERITY_WARNING,
                CurationFlag::SEVERITY_INFO,
            ])],
        ];
    }
}
