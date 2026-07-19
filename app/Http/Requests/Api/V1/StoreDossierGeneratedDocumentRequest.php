<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajout d'un document généré (mise en demeure, statuts…) à un dossier (web),
 * avec son contenu HTML imprimable.
 */
class StoreDossierGeneratedDocumentRequest extends FormRequest
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
            'template_id' => ['required', 'string', 'max:255'],
            'template_name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'html' => ['required', 'string'],
        ];
    }
}
