<?php

namespace App\Services\Onboarding;

/**
 * Négociation de capacités client — mibeko-dashboard#136.
 *
 * Le client annonce sa plateforme et les types d'étape qu'il sait rendre ;
 * le serveur filtre/annote sans jamais bloquer : une étape de type inconnu
 * n'est pas retirée, elle est marquée `supported: false` et le client est
 * censé la sauter silencieusement. Aucun header de version/capacités
 * n'existait avant #136 — conçu de zéro, pas de précédent à réutiliser.
 */
class OnboardingCapabilityFilter
{
    /**
     * @param  list<array<string, mixed>>  $definition
     * @param  list<string>  $knownStepTypes
     * @return list<array<string, mixed>>
     */
    public function annotate(array $definition, string $platform, array $knownStepTypes): array
    {
        return collect($definition)
            ->filter(fn (array $step) => in_array($step['scope'], ['common', $platform], true))
            ->map(fn (array $step) => [...$step, 'supported' => in_array($step['type'], $knownStepTypes, true)])
            ->values()
            ->all();
    }

    /**
     * Clés d'étape après filtrage par `scope` uniquement (PAS par
     * "supported") : une étape non supportée par le client doit quand même
     * compter comme "résolue" côté serveur dès qu'elle est vue/sautée, sinon
     * un client ancien ne pourrait jamais faire progresser l'inscription
     * vers `completed`.
     *
     * @param  list<array<string, mixed>>  $definition
     * @return list<string>
     */
    public function applicableStepKeys(array $definition, string $platform): array
    {
        return collect($definition)
            ->filter(fn (array $step) => in_array($step['scope'], ['common', $platform], true))
            ->pluck('key')
            ->values()
            ->all();
    }
}
