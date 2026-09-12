<?php

namespace Database\Seeders;

use App\Models\MobileProfile;
use App\Models\OnboardingJourney;
use Illuminate\Database\Seeder;

/**
 * Parcours v1 déterministe — mibeko-dashboard#136. Contenu validé avec
 * l'utilisateur : bienvenue → cadre d'usage → centres d'intérêt →
 * découverte des sources → fin. Toutes les étapes sont facultatives ;
 * aucune ne collecte le téléphone (aucune finalité affichée concrète tant
 * que #125/#126 — veille/rappel — n'existent pas).
 *
 * Rejouable sans dupliquer : `OnboardingJourney::publish()` est idempotent
 * par contenu (compare un hash de la définition à la version active).
 */
class OnboardingJourneySeeder extends Seeder
{
    public function run(): void
    {
        OnboardingJourney::publish('onboarding', [
            [
                'key' => 'welcome',
                'type' => OnboardingJourney::TYPE_WELCOME,
                'scope' => 'common',
                'binding' => null,
                'config' => [
                    'title_key' => 'onboarding.welcome.title',
                    'body_key' => 'onboarding.welcome.body',
                ],
                'conditions' => [],
            ],
            [
                'key' => 'usage_context',
                'type' => OnboardingJourney::TYPE_SINGLE_CHOICE,
                'scope' => 'common',
                'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT,
                'config' => [
                    'options' => collect(MobileProfile::USAGE_CONTEXTS)
                        ->map(fn (string $code) => ['code' => $code, 'label_key' => "onboarding.usage_context.{$code}"])
                        ->all(),
                ],
                'conditions' => [],
            ],
            [
                'key' => 'interests',
                'type' => OnboardingJourney::TYPE_MULTI_CHOICE,
                'scope' => 'common',
                'binding' => OnboardingJourney::BINDING_INTERESTS,
                // Pas d'options inlinées : le client connaît déjà le catalogue
                // (taxonomie "Thèmes de vie" existante, GET library/themes) —
                // éviter de dupliquer une taxonomie qui a déjà sa propre
                // source de vérité.
                'config' => ['source' => 'tags:themes-de-vie'],
                'conditions' => [],
            ],
            [
                'key' => 'discover_sources',
                'type' => OnboardingJourney::TYPE_GUIDED_ACTION,
                'scope' => 'common',
                'binding' => null,
                'config' => [
                    'title_key' => 'onboarding.discover_sources.title',
                    'cta_key' => 'onboarding.discover_sources.cta',
                ],
                'conditions' => [],
            ],
        ]);
    }
}
