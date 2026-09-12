<?php

use App\Models\OnboardingJourney;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    OnboardingJourney::publish('onboarding', [
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'p']]], 'conditions' => []],
        ['key' => 'phone', 'type' => OnboardingJourney::TYPE_OPTIONAL_FIELD, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_PHONE, 'config' => [], 'conditions' => []],
    ]);
});

/**
 * mibeko-dashboard#136 : l'export RGPD inclut la progression du compte
 * courant, exclut celle d'un autre, et ne fuit jamais la valeur brute d'un
 * binding sensible (déjà NULL en base — l'export ne fait que refléter l'état stocké).
 */
it('inclut la progression d\'onboarding du compte courant dans l\'export', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['onboarding'])->toHaveCount(1);
    expect($payload['onboarding'][0]['journey_key'])->toBe('onboarding');
    $step = collect($payload['onboarding'][0]['steps'])->firstWhere('step_key', 'usage_context');
    expect($step['value'])->toBe('personal');
});

it('exclut la progression d\'un autre compte', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $this->actingAs($userA)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    $response = $this->actingAs($userB)->get('/api/v1/profile/export');
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['onboarding'])->toHaveCount(0);
});

it('n\'expose jamais la valeur brute d\'un binding sensible dans l\'export', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/phone', [
        'action' => 'answer', 'value' => '+242068000000', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $payload = json_decode($response->streamedContent(), true);

    $step = collect($payload['onboarding'][0]['steps'])->firstWhere('step_key', 'phone');
    expect($step['value'])->toBeNull();
    expect($payload['profile']['phone'])->toBe('+242068000000');
});
