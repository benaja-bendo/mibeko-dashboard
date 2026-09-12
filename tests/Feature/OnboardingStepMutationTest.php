<?php

use App\Models\MobileProfile;
use App\Models\OnboardingJourney;
use App\Models\OnboardingStepProgress;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    OnboardingJourney::publish('onboarding', [
        ['key' => 'welcome', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'p'], ['code' => 'professional', 'label_key' => 'pr']]], 'conditions' => []],
        ['key' => 'interests', 'type' => OnboardingJourney::TYPE_MULTI_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_INTERESTS, 'config' => [], 'conditions' => []],
        ['key' => 'phone', 'type' => OnboardingJourney::TYPE_OPTIONAL_FIELD, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_PHONE, 'config' => [], 'conditions' => []],
        ['key' => 'discover_sources', 'type' => OnboardingJourney::TYPE_GUIDED_ACTION, 'scope' => 'common', 'binding' => null, 'config' => [], 'conditions' => []],
    ]);
});

function patchStep(User $user, string $step, array $body): TestResponse
{
    return test()->actingAs($user)->patchJson("/api/v1/onboarding/steps/{$step}", [
        'client_mutation_id' => 'm-'.uniqid(), 'client_updated_at' => 1000, 'platform' => 'web', ...$body,
    ]);
}

it('complete_at n\'est jamais réinitialisé par un replay', function () {
    $user = User::factory()->create();

    patchStep($user, 'welcome', ['action' => 'skip']);
    patchStep($user, 'usage_context', ['action' => 'answer', 'value' => 'personal']);
    patchStep($user, 'interests', ['action' => 'skip']);
    patchStep($user, 'phone', ['action' => 'skip']);
    patchStep($user, 'discover_sources', ['action' => 'skip']);

    $before = OnboardingStepProgress::where('step_key', 'usage_context')->first()->completed_at;

    $this->actingAs($user)->postJson('/api/v1/onboarding/replay', ['client_mutation_id' => 'r1'])->assertOk();

    // Répondre à nouveau après le replay ne doit pas déplacer completed_at.
    patchStep($user, 'usage_context', ['action' => 'answer', 'value' => 'professional']);

    $after = OnboardingStepProgress::where('step_key', 'usage_context')->first()->fresh()->completed_at;

    expect($after->eq($before))->toBeTrue();
});

it('skipped_at ne déclenche jamais completed_at, une vraie réponse ultérieure pose completed_at sans effacer skipped_at', function () {
    $user = User::factory()->create();

    patchStep($user, 'usage_context', ['action' => 'skip']);
    $progress = OnboardingStepProgress::where('step_key', 'usage_context')->first();
    expect($progress->skipped_at)->not->toBeNull();
    expect($progress->completed_at)->toBeNull();

    patchStep($user, 'usage_context', ['action' => 'answer', 'value' => 'personal']);
    $progress = $progress->fresh();
    expect($progress->completed_at)->not->toBeNull();
    expect($progress->skipped_at)->not->toBeNull();
});

it('rejoue un mutation_id identique sans réécrire mobile_profiles', function () {
    $user = User::factory()->create();

    $mutationId = 'stable-id';
    $body = ['action' => 'answer', 'value' => 'professional', 'client_mutation_id' => $mutationId, 'client_updated_at' => 1000, 'platform' => 'web'];

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', $body)->assertOk();

    $writes = 0;
    DB::listen(function ($query) use (&$writes) {
        if (str_contains(strtolower($query->sql), 'insert into "mobile_profiles"') || str_contains(strtolower($query->sql), 'update "mobile_profiles"')) {
            $writes++;
        }
    });

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', $body)->assertOk();

    expect($writes)->toBe(0);
});

it('ignore une mutation périmée (LWW) sans écraser une réponse plus récente', function () {
    $user = User::factory()->create();

    patchStep($user, 'usage_context', ['action' => 'answer', 'value' => 'professional', 'client_updated_at' => 2000]);

    // Mutation plus ancienne, arrivée en retard (reprise hors ligne).
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'old', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    expect(OnboardingStepProgress::where('step_key', 'usage_context')->first()->value)->toBe('professional');
    expect(MobileProfile::first()->usage_context)->toBe('professional');
});

it('ne fuit pas entre deux comptes avec le même client_mutation_id littéral', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'shared-id', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    $this->actingAs($userB)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'professional', 'client_mutation_id' => 'shared-id', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    $progressA = OnboardingStepProgress::whereHas('enrollment', fn ($q) => $q->where('user_id', $userA->id))->where('step_key', 'usage_context')->first();
    $progressB = OnboardingStepProgress::whereHas('enrollment', fn ($q) => $q->where('user_id', $userB->id))->where('step_key', 'usage_context')->first();

    expect($progressA->value)->toBe('personal');
    expect($progressB->value)->toBe('professional');
});

it('refuse un choix simple hors catalogue', function () {
    $user = User::factory()->create();

    patchStep($user, 'usage_context', ['action' => 'answer', 'value' => 'inconnu'])->assertStatus(422);
});

it('refuse un slug d\'intérêt inconnu', function () {
    $user = User::factory()->create();

    patchStep($user, 'interests', ['action' => 'answer', 'value' => ['inconnu']])->assertStatus(422);
});

it('accepte les intérêts et synchronise les tags', function () {
    $user = User::factory()->create();
    $tag = Tag::create(['name' => 'Famille', 'slug' => 'famille']);

    patchStep($user, 'interests', ['action' => 'answer', 'value' => [$tag->slug]])->assertOk();

    expect($user->fresh()->tags->pluck('slug')->all())->toBe([$tag->slug]);
});

it('refuse un téléphone invalide sur une étape optional_field liée au profil', function () {
    $user = User::factory()->create();

    patchStep($user, 'phone', ['action' => 'answer', 'value' => 'abc'])->assertStatus(422);
});

it('n\'enregistre jamais la valeur brute d\'un binding sensible dans onboarding_step_progress', function () {
    $user = User::factory()->create();

    patchStep($user, 'phone', ['action' => 'answer', 'value' => '+242068000000'])->assertOk();

    expect(OnboardingStepProgress::where('step_key', 'phone')->first()->value)->toBeNull();
    expect(MobileProfile::first()->phone)->toBe('+242068000000');
});

it('évalue les conditions déclaratives pour filtrer l\'affichage d\'une étape', function () {
    OnboardingJourney::publish('onboarding', [
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'p'], ['code' => 'professional', 'label_key' => 'pr']]], 'conditions' => []],
        ['key' => 'job_title', 'type' => OnboardingJourney::TYPE_OPTIONAL_FIELD, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_JOB_TITLE, 'config' => [], 'conditions' => [['step_key' => 'usage_context', 'operator' => 'equals', 'value' => 'professional']]],
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web');
    expect(collect($response->json('data.journey.steps'))->pluck('key')->all())->not->toContain('job_title');

    patchStep($user, 'usage_context', ['action' => 'answer', 'value' => 'professional']);

    $response = $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web');
    expect(collect($response->json('data.journey.steps'))->pluck('key')->all())->toContain('job_title');
});

it('aucun rôle ni entitlement n\'est accordé par la progression', function () {
    $user = User::factory()->create();
    $before = $this->actingAs($user)->getJson('/api/v1/me/entitlements')->json('data.plan');

    patchStep($user, 'usage_context', ['action' => 'answer', 'value' => 'professional'])->assertOk();

    $this->actingAs($user)->getJson('/api/v1/me/entitlements')->assertJsonPath('data.plan', $before);
});
