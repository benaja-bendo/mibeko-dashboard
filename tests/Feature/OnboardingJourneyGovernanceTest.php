<?php

use App\Models\OnboardingEnrollment;
use App\Models\OnboardingJourney;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    OnboardingJourney::publish('onboarding', [
        ['key' => 'welcome', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'p'], ['code' => 'professional', 'label_key' => 'pr']]], 'conditions' => []],
    ]);
});

/**
 * mibeko-dashboard#136 : machine à états de l'inscription, façon
 * PublicationGovernanceTest — transitions autorisées/refusées.
 */
it('démarre non_commencé puis passe en cours au premier PATCH', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web')
        ->assertOk()
        ->assertJsonPath('data.enrollment.status', OnboardingEnrollment::STATUS_NOT_STARTED);

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/welcome', [
        'action' => 'view', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk()->assertJsonPath('data.enrollment.status', OnboardingEnrollment::STATUS_IN_PROGRESS);
});

it('passe en terminé quand toutes les étapes applicables sont résolues', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/welcome', [
        'action' => 'skip', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ]);
    $response = $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'skip', 'client_mutation_id' => 'm2', 'client_updated_at' => 1001, 'platform' => 'web',
    ]);

    $response->assertOk()->assertJsonPath('data.enrollment.status', OnboardingEnrollment::STATUS_COMPLETED);
});

it('refuse de repasser une inscription terminée en cours via un PATCH direct', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/welcome', ['action' => 'skip', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web']);
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', ['action' => 'skip', 'client_mutation_id' => 'm2', 'client_updated_at' => 1001, 'platform' => 'web']);

    $response = $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'm3', 'client_updated_at' => 1002, 'platform' => 'web',
    ]);

    $response->assertOk()->assertJsonPath('data.enrollment.status', OnboardingEnrollment::STATUS_COMPLETED);
    expect(OnboardingEnrollment::first()->fresh()->status)->toBe(OnboardingEnrollment::STATUS_COMPLETED);
});

it('accepte completed → in_progress uniquement via /replay', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/welcome', ['action' => 'skip', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web']);
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', ['action' => 'skip', 'client_mutation_id' => 'm2', 'client_updated_at' => 1001, 'platform' => 'web']);

    $this->actingAs($user)->postJson('/api/v1/onboarding/replay', ['client_mutation_id' => 'r1'])
        ->assertOk()
        ->assertJsonPath('data.enrollment.status', OnboardingEnrollment::STATUS_IN_PROGRESS)
        ->assertJsonPath('data.enrollment.replay_count', 1);
});

it('refuse /replay tant que le parcours n\'est pas terminé', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/onboarding/replay', ['client_mutation_id' => 'r1'])
        ->assertStatus(422);
});

it('/postpone est idempotent', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/onboarding/postpone', ['client_mutation_id' => 'p1'])
        ->assertOk()->assertJsonPath('data.enrollment.status', OnboardingEnrollment::STATUS_POSTPONED);

    $firstPostponedAt = OnboardingEnrollment::first()->postponed_at;

    $this->actingAs($user)->postJson('/api/v1/onboarding/postpone', ['client_mutation_id' => 'p1'])
        ->assertOk()->assertJsonPath('data.enrollment.status', OnboardingEnrollment::STATUS_POSTPONED);

    expect(OnboardingEnrollment::first()->fresh()->postponed_at->eq($firstPostponedAt))->toBeTrue();
});
