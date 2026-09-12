<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OnboardingJourney;
use App\Models\OnboardingStepProgress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mutation d'une étape — mibeko-dashboard#136. `value` n'est pas validée ici
 * (dépend du TYPE de l'étape ciblée, connu seulement une fois la définition
 * chargée) : `OnboardingAnswerValidator` s'en charge dans le contrôleur.
 * `client_mutation_id`/`client_updated_at` portent l'idempotence et le LWW
 * (`OnboardingStepWriter`), `platform` la négociation de capacités.
 */
class OnboardingStepMutationRequest extends FormRequest
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
            'action' => ['required', Rule::in(OnboardingStepProgress::ACTIONS)],
            'value' => ['nullable'],
            'client_mutation_id' => ['required', 'string', 'max:100'],
            'client_updated_at' => ['required', 'integer'],
            'platform' => ['required', Rule::in(OnboardingJourney::PLATFORMS)],
        ];
    }
}
