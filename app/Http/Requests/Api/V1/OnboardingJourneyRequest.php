<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OnboardingJourney;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Négociation de capacités du client — mibeko-dashboard#136. `platform`
 * détermine quelles étapes `scope: web|mobile` sont visibles (en plus de
 * `common`, toujours visible) ; `known_step_types` détermine quelles
 * étapes sont annotées `supported: true` (jamais bloquant sinon).
 */
class OnboardingJourneyRequest extends FormRequest
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
            'platform' => ['required', Rule::in(OnboardingJourney::PLATFORMS)],
            'known_step_types' => ['sometimes', 'array'],
            'known_step_types.*' => ['string', Rule::in(OnboardingJourney::STEP_TYPES)],
        ];
    }
}
