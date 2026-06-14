<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * Normalise le code en MAJUSCULES : c'est une clé primaire stable et
     * lisible (LOI, DEC, ARR…), jamais saisie deux fois de la même façon.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => Str::upper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_]+$/', 'unique:document_types,code'],
            'name' => ['required', 'string', 'max:255'],
            'hierarchy_level' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Le code ne peut contenir que des lettres majuscules, chiffres et underscores.',
            'code.unique' => 'Un type avec ce code existe déjà.',
        ];
    }
}
