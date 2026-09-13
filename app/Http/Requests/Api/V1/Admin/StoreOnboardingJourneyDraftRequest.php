<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreOnboardingJourneyDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'definition' => ['sometimes', 'array', 'min:1', 'max:20'],
        ];
    }
}
