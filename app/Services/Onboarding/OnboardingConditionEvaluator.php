<?php

namespace App\Services\Onboarding;

/**
 * Évalue les conditions déclaratives bornées d'une étape — mibeko-dashboard#136.
 *
 * Ensemble FERMÉ d'opérateurs (`OnboardingJourney::CONDITION_OPERATORS`) :
 * jamais d'`eval()`, de SQL dynamique ou de JS arbitraire. Régit l'affichage
 * d'une étape (`GET /onboarding/journey`), pas une frontière de sécurité —
 * `PATCH /onboarding/steps/{key}` reste acceptée même sur une étape
 * actuellement condition-gated, le catalogue d'étapes étant borné.
 */
class OnboardingConditionEvaluator
{
    /**
     * @param  list<array{step_key: string, operator: string, value: mixed}>  $conditions
     * @param  array<string, mixed>  $resolvedValues  step_key => valeur déjà répondue
     */
    public function passes(array $conditions, array $resolvedValues): bool
    {
        foreach ($conditions as $condition) {
            $actual = $resolvedValues[$condition['step_key']] ?? null;

            $passes = match ($condition['operator']) {
                'equals' => $actual === $condition['value'],
                'not_equals' => $actual !== $condition['value'],
                'in' => in_array($actual, (array) $condition['value'], true),
                default => false,
            };

            if (! $passes) {
                return false;
            }
        }

        return true;
    }
}
