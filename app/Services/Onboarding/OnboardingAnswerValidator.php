<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingJourney;
use App\Rules\InternationalPhoneNumber;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Valide `value` selon le TYPE de l'étape répondue — mibeko-dashboard#136.
 * `view`/`skip` n'ont pas de valeur à valider. Réutilise les mêmes règles
 * que `UpdateProfileRequest` (#135) pour les étapes liées au profil, plutôt
 * que d'en réinventer une copie divergente.
 */
class OnboardingAnswerValidator
{
    /**
     * @param  array<string, mixed>  $stepDefinition
     * @param  array<string, mixed>  $mutation
     */
    public function validate(array $stepDefinition, array $mutation): void
    {
        if ($mutation['action'] !== 'answer') {
            return;
        }

        $rules = match ($stepDefinition['type']) {
            OnboardingJourney::TYPE_SINGLE_CHOICE => [
                'value' => ['required', 'string', Rule::in($this->optionCodes($stepDefinition))],
            ],
            OnboardingJourney::TYPE_MULTI_CHOICE => $this->multiChoiceRules($stepDefinition),
            OnboardingJourney::TYPE_OPTIONAL_FIELD => $this->optionalFieldRules($stepDefinition['binding'] ?? null),
            default => [],
        };

        Validator::make($mutation, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $stepDefinition
     * @return list<string>
     */
    private function optionCodes(array $stepDefinition): array
    {
        return collect($stepDefinition['config']['options'] ?? [])->pluck('code')->all();
    }

    /**
     * @param  array<string, mixed>  $stepDefinition
     * @return array<string, mixed>
     */
    private function multiChoiceRules(array $stepDefinition): array
    {
        if (($stepDefinition['binding'] ?? null) === OnboardingJourney::BINDING_INTERESTS) {
            return [
                'value' => ['array'],
                'value.*' => ['string', 'distinct', Rule::exists('tags', 'slug')],
            ];
        }

        return [
            'value' => ['array'],
            'value.*' => ['string', 'distinct', Rule::in($this->optionCodes($stepDefinition))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function optionalFieldRules(?string $binding): array
    {
        return match ($binding) {
            OnboardingJourney::BINDING_PHONE => ['value' => ['nullable', 'string', 'max:30', new InternationalPhoneNumber]],
            OnboardingJourney::BINDING_JOB_TITLE, OnboardingJourney::BINDING_COMPANY => ['value' => ['nullable', 'string', 'max:255']],
            default => ['value' => ['nullable']],
        };
    }
}
