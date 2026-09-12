<?php

namespace App\Services\Onboarding;

use App\Models\OnboardingJourney;
use App\Models\OnboardingStepProgress;
use App\Services\ProfileAttributeWriter;

/**
 * Écriture idempotente d'une mutation de progression — mibeko-dashboard#136.
 *
 * Règle les 3 cas exigés par le ticket avec une seule discipline, à
 * appliquer sur une ligne déjà verrouillée (`lockForUpdate()`, cf.
 * `OnboardingController::lockedEnrollment()`) :
 * 1. Concurrence réelle → sérialisée par le verrou appelant, pas ici.
 * 2. Doublon réseau (même `client_mutation_id` rejoué) → no-op reconnu.
 * 3. Reprise hors ligne (mutation périmée) → LWW sur `client_updated_at`
 *    (même doctrine que `DossierController::mergeDossier`, jamais l'horloge
 *    serveur).
 *
 * `completed_at` est IMMUABLE : une fois posé, plus aucune mutation ne peut
 * le changer (`isTerminal()` court-circuite tout le reste).
 */
class OnboardingStepWriter
{
    public function __construct(private readonly ProfileAttributeWriter $profileWriter) {}

    /**
     * @param  array<string, mixed>  $stepDefinition
     * @param  array{action: string, value?: mixed, client_mutation_id: string, client_updated_at: int}  $mutation
     */
    public function apply(OnboardingStepProgress $progress, array $stepDefinition, array $mutation): void
    {
        if ($progress->isTerminal()) {
            return;
        }

        if ($progress->last_client_mutation_id !== null
            && $progress->last_client_mutation_id === $mutation['client_mutation_id']) {
            return;
        }

        if ($progress->client_updated_at !== null && $mutation['client_updated_at'] < $progress->client_updated_at) {
            return;
        }

        $binding = $stepDefinition['binding'] ?? null;
        $rawValue = $mutation['value'] ?? null;
        // La valeur brute sert à écrire le binding (mobile_profiles/tags) ;
        // la valeur STOCKÉE ici reste NULL pour un binding sensible — jamais
        // de téléphone dupliqué dans un événement de progression.
        $storedValue = in_array($binding, OnboardingJourney::SENSITIVE_BINDINGS, true) ? null : $rawValue;

        match ($mutation['action']) {
            OnboardingStepProgress::ACTION_VIEW => $progress->viewed_at ??= now(),
            OnboardingStepProgress::ACTION_SKIP => $progress->skipped_at ??= now(),
            OnboardingStepProgress::ACTION_ANSWER => $this->answer($progress, $binding, $rawValue, $storedValue),
        };

        $progress->last_client_mutation_id = $mutation['client_mutation_id'];
        $progress->client_updated_at = $mutation['client_updated_at'];
        $progress->save();
    }

    private function answer(OnboardingStepProgress $progress, ?string $binding, mixed $rawValue, mixed $storedValue): void
    {
        $progress->value = $storedValue;
        $progress->completed_at = now();

        if ($binding !== null) {
            $this->applyBinding($progress, $binding, $rawValue);
        }
    }

    private function applyBinding(OnboardingStepProgress $progress, string $binding, mixed $value): void
    {
        $user = $progress->enrollment->user;

        if ($binding === OnboardingJourney::BINDING_INTERESTS) {
            $this->profileWriter->applyInterests($user, (array) $value);

            return;
        }

        // "profile.usage_context" -> "usage_context", etc.
        $field = substr($binding, strlen('profile.'));
        $this->profileWriter->applyProfileFields($user, [$field => $value]);
    }
}
