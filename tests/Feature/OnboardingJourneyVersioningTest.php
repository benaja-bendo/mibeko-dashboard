<?php

use App\Models\OnboardingEnrollment;
use App\Models\OnboardingJourney;
use App\Models\OnboardingStepProgress;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * mibeko-dashboard#136 : « Version A commencée reste A après publication B ;
 * nouveaux comptes reçoivent B ; retour à A possible. »
 */
it('une inscription déjà créée sur la version A reste épinglée à A après publication de B', function () {
    $journeyA = OnboardingJourney::publish('onboarding', [
        ['key' => 'a_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);
    $userA = User::factory()->create();
    // Crée l'inscription (épingle journey_id = A) via un premier appel.
    $this->actingAs($userA)->getJson('/api/v1/onboarding/journey?platform=web')->assertOk();

    $journeyB = OnboardingJourney::publish('onboarding', [
        ['key' => 'b_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);

    expect($journeyA->id)->not->toBe($journeyB->id);
    expect(OnboardingEnrollment::where('user_id', $userA->id)->first()->journey_id)->toBe($journeyA->id);

    $stepsA = collect($this->actingAs($userA)->getJson('/api/v1/onboarding/journey?platform=web')->json('data.journey.steps'))->pluck('key');
    expect($stepsA)->toContain('a_only')->not->toContain('b_only');
});

it('un nouveau compte reçoit la version active B après sa publication', function () {
    OnboardingJourney::publish('onboarding', [
        ['key' => 'a_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);
    OnboardingJourney::publish('onboarding', [
        ['key' => 'b_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);

    $newUser = User::factory()->create();
    $steps = collect($this->actingAs($newUser)->getJson('/api/v1/onboarding/journey?platform=web')->json('data.journey.steps'))->pluck('key');

    expect($steps)->toContain('b_only')->not->toContain('a_only');
});

it('republier A comme active permet un retour en arrière pour les nouveaux comptes', function () {
    $journeyA = OnboardingJourney::publish('onboarding', [
        ['key' => 'a_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);
    OnboardingJourney::publish('onboarding', [
        ['key' => 'b_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);

    // "Retour à A" = republier le même contenu que A (nouvelle ligne, A reste
    // immuable) — jamais un UPDATE de la ligne A existante ni une
    // réactivation directe de l'ancienne ligne.
    $journeyA3 = OnboardingJourney::publish('onboarding', [
        ['key' => 'a_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);

    expect($journeyA3->id)->not->toBe($journeyA->id);
    expect($journeyA3->version)->toBe(3);
    expect(OnboardingJourney::where('is_active', true)->count())->toBe(1);

    $newUser = User::factory()->create();
    $steps = collect($this->actingAs($newUser)->getJson('/api/v1/onboarding/journey?platform=web')->json('data.journey.steps'))->pluck('key');
    expect($steps)->toContain('a_only');
});

/**
 * « Rejeu, report et changement de profil préservent les premières réussites
 * et les droits. »
 */
it('modifier le profil directement (hors onboarding) ne touche pas les premières réussites déjà enregistrées', function () {
    OnboardingJourney::publish('onboarding', [
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'p'], ['code' => 'professional', 'label_key' => 'pr']]], 'conditions' => []],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    $completedAt = OnboardingStepProgress::where('step_key', 'usage_context')->first()->completed_at;

    // Changement de profil DIRECT (#135), pas via l'onboarding.
    $this->actingAs($user)->putJson('/api/v1/profile', ['usage_context' => 'professional'])->assertOk();

    $progress = OnboardingStepProgress::where('step_key', 'usage_context')->first()->fresh();
    expect($progress->completed_at->eq($completedAt))->toBeTrue();

    // Les droits (rôle/entitlement) restent indépendants du changement.
    $this->actingAs($user)->getJson('/api/v1/me/entitlements')->assertJsonPath('data.plan', 'libre');
});
