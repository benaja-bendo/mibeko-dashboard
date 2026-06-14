<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurationFlagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * Seul l'état de résolution est modifiable côté triage (résoudre /
     * ré-ouvrir). Le contenu du signalement reste tel qu'émis par l'auteur.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resolved' => ['required', 'boolean'],
        ];
    }
}
