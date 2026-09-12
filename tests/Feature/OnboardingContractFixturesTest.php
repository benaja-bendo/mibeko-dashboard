<?php

use App\Models\OnboardingJourney;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;

/**
 * mibeko-dashboard#136 — jeu de contrat commun pour front#40/mobile#45.
 *
 * `dedoc/scramble` documente la forme générique de l'enveloppe API, mais ne
 * peut pas deviner la forme concrète d'un `jsonb` opaque (`definition`,
 * `value`) : ces fixtures comblent ce trou. Écrites une seule fois (si le
 * fichier n'existe pas encore) à partir d'une réponse réelle déjà vérifiée
 * correcte par les autres tests de #136, puis comparées à l'identique à
 * chaque exécution suivante — le fichier ne peut pas devenir mensonger sans
 * faire échouer ce test.
 */
function assertMatchesOnboardingFixture(string $name, array $actual): void
{
    $path = base_path("tests/Fixtures/Onboarding/{$name}.json");

    if (! file_exists($path)) {
        file_put_contents(
            $path,
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    expect($actual)->toEqual(json_decode(file_get_contents($path), true));
}

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Carbon::setTestNow(Carbon::parse('2026-01-01T00:00:00+00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('correspond au contrat pour un parcours neuf', function () {
    OnboardingJourney::publish('onboarding', [
        ['key' => 'welcome', 'type' => OnboardingJourney::TYPE_WELCOME, 'scope' => 'common', 'binding' => null, 'config' => ['title_key' => 'onboarding.welcome.title'], 'conditions' => []],
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'onboarding.usage_context.personal']]], 'conditions' => []],
    ]);
    $user = User::factory()->create();

    $data = $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web')->assertOk()->json('data');

    assertMatchesOnboardingFixture('journey-not-started', $data);
});

it('correspond au contrat pour un parcours en cours', function () {
    OnboardingJourney::publish('onboarding', [
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'onboarding.usage_context.personal']]], 'conditions' => []],
        ['key' => 'discover_sources', 'type' => OnboardingJourney::TYPE_GUIDED_ACTION, 'scope' => 'common', 'binding' => null, 'config' => ['title_key' => 'onboarding.discover_sources.title', 'cta_key' => 'onboarding.discover_sources.cta'], 'conditions' => []],
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk();

    $data = $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web')->assertOk()->json('data');

    assertMatchesOnboardingFixture('journey-in-progress', $data);
});

it('correspond au contrat pour une étape non supportée par le client', function () {
    OnboardingJourney::publish('onboarding', [
        ['key' => 'discover_sources', 'type' => OnboardingJourney::TYPE_GUIDED_ACTION, 'scope' => 'common', 'binding' => null, 'config' => ['title_key' => 'onboarding.discover_sources.title', 'cta_key' => 'onboarding.discover_sources.cta'], 'conditions' => []],
    ]);
    $user = User::factory()->create();

    $data = $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web&known_step_types[]=welcome')
        ->assertOk()->json('data');

    assertMatchesOnboardingFixture('journey-unsupported-step', $data);
});

it('correspond au contrat quand aucune définition n\'est active', function () {
    $user = User::factory()->create();

    $data = $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web')->assertOk()->json('data');

    assertMatchesOnboardingFixture('journey-unavailable', $data);
});
