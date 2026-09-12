<?php

use App\Models\OnboardingJourney;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * mibeko-dashboard#136 — négociation de capacités client : jamais bloquante,
 * jamais 404/500 même sans définition active.
 */
it('marque une étape supported:false quand le client ne connaît pas son type, sans jamais bloquer', function () {
    OnboardingJourney::publish('onboarding', [
        ['key' => 'discover_sources', 'type' => OnboardingJourney::TYPE_GUIDED_ACTION, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson(
        '/api/v1/onboarding/journey?platform=web&known_step_types[]=welcome&known_step_types[]=single_choice'
    )->assertOk();

    $steps = collect($response->json('data.journey.steps'));
    expect($steps->firstWhere('key', 'discover_sources')['supported'])->toBeFalse();
});

it('platform filtre les étapes scopées, common reste toujours visible', function () {
    OnboardingJourney::publish('onboarding', [
        ['key' => 'welcome', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
        ['key' => 'mobile_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'mobile', 'binding' => null, 'config' => [], 'conditions' => []],
        ['key' => 'web_only', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'web', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);
    $user = User::factory()->create();

    $webKeys = collect($this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web')->json('data.journey.steps'))->pluck('key');
    expect($webKeys)->toContain('welcome')->toContain('web_only')->not->toContain('mobile_only');

    $mobileKeys = collect($this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=mobile')->json('data.journey.steps'))->pluck('key');
    expect($mobileKeys)->toContain('welcome')->toContain('mobile_only')->not->toContain('web_only');
});

it('répond available:false sans erreur quand aucune définition active n\'existe', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web')
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.journey', null);
});
