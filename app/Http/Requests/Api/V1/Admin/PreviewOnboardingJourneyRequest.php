<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\OnboardingJourney;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewOnboardingJourneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'definition' => ['required', 'array', 'min:1', 'max:20'],
            'platform' => ['required', 'string', Rule::in(OnboardingJourney::PLATFORMS)],
            'known_step_types' => ['sometimes', 'array'],
            'known_step_types.*' => ['string', Rule::in(OnboardingJourney::STEP_TYPES)],
            'answers' => ['sometimes', 'array', 'max:20'],
        ];
    }
}
