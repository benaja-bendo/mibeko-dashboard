<?php

use App\Models\MobileProfile;
use App\Models\OnboardingJourney;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    OnboardingJourney::publish('onboarding', [
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'p'], ['code' => 'professional', 'label_key' => 'pr']]], 'conditions' => []],
        ['key' => 'interests', 'type' => OnboardingJourney::TYPE_MULTI_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_INTERESTS, 'config' => [], 'conditions' => []],
    ]);
});

/**
 * mibeko-dashboard#136 — le binding d'une étape écrit via le même chemin que
 * ProfileController::update() (#135), réutilisé par ProfileAttributeWriter.
 */
it('répondre à usage_context écrit mobile_profiles.usage_context et dérive profession', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'professional', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    $profile = MobileProfile::where('user_id', $user->id)->first();
    expect($profile->usage_context)->toBe('professional');
    expect($profile->profession)->toBe('Professionnel du droit');
});

it('répondre à interests fait un vrai sync() des tags, pas un merge', function () {
    $user = User::factory()->create();
    $famille = Tag::create(['name' => 'Famille', 'slug' => 'famille']);
    $travail = Tag::create(['name' => 'Travail', 'slug' => 'travail']);
    $user->tags()->sync([$famille->id]);

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/interests', [
        'action' => 'answer', 'value' => [$travail->slug], 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    expect($user->fresh()->tags->pluck('slug')->all())->toBe([$travail->slug]);
});
