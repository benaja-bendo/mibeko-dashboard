<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * Génère le slug à partir du nom quand il n'est pas fourni, pour que la
     * règle d'unicité s'applique aussi au slug auto-calculé.
     */
    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name'));
        $slug = $this->filled('slug') ? $this->input('slug') : $name;

        $this->merge([
            'name' => $name,
            'slug' => Str::slug((string) $slug),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:tags,name'],
            'slug' => ['required', 'string', 'max:255', 'unique:tags,slug'],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Un tag portant ce nom existe déjà.',
            'slug.unique' => 'Un tag avec ce slug existe déjà.',
        ];
    }
}
