<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * Le `code` est volontairement absent : c'est la clé primaire (référencée
     * par legal_documents.type_code), donc immuable. Seuls le libellé et le
     * niveau hiérarchique sont modifiables.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'hierarchy_level' => ['required', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
