<?php

use App\Models\OnboardingEnrollment;
use App\Models\OnboardingJourney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Role::findOrCreate('admin');
    Role::findOrCreate('mobile_user');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->member = User::factory()->create();
    $this->member->assignRole('mobile_user');
});

function onboardingDefinition(string $title = 'Bienvenue sur Mibeko'): array
{
    return [
        ['key' => 'welcome', 'type' => 'welcome', 'scope' => 'common', 'binding' => null, 'config' => ['title' => $title, 'body' => 'Un accueil court et utile.'], 'conditions' => []],
        ['key' => 'usage_context', 'type' => 'single_choice', 'scope' => 'common', 'binding' => 'profile.usage_context', 'config' => ['title' => 'Votre usage', 'options' => [
            ['code' => 'personal', 'label' => 'Personnel'],
            ['code' => 'professional', 'label' => 'Professionnel'],
        ]], 'conditions' => []],
        ['key' => 'pro_tip', 'type' => 'checklist', 'scope' => 'web', 'binding' => null, 'config' => ['title' => 'Conseils professionnels', 'items' => [['code' => 'search', 'label' => 'Lancer une recherche']]], 'conditions' => [
            ['step_key' => 'usage_context', 'operator' => 'equals', 'value' => 'professional'],
        ]],
    ];
}

it('réserve toutes les routes de l’éditeur aux administrateurs', function () {
    $this->getJson('/api/v1/admin/onboarding-journeys')->assertUnauthorized();
    $this->actingAs($this->member)->getJson('/api/v1/admin/onboarding-journeys')->assertForbidden();
});

it('crée un unique brouillon en copiant la version active', function () {
    $active = OnboardingJourney::publish('onboarding', onboardingDefinition());
    $first = $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/drafts')
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.definition.0.config.title', 'Bienvenue sur Mibeko');
    $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/drafts')
        ->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));

    expect(OnboardingJourney::where('status', 'draft')->count())->toBe(1)
        ->and($active->fresh()->is_active)->toBeTrue();
});

it('enregistre et audite le contenu texte brut d’un brouillon', function () {
    OnboardingJourney::publish('onboarding', onboardingDefinition());
    $draftId = $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/drafts')->json('data.id');
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/onboarding-journeys/{$draftId}", [
        'definition' => onboardingDefinition('Une nouvelle accroche'),
    ])->assertOk()->assertJsonPath('data.definition.0.config.title', 'Une nouvelle accroche');

    expect(Audit::query()->where('auditable_type', OnboardingJourney::class)
        ->where('auditable_id', $draftId)->where('user_id', $this->admin->id)->exists())->toBeTrue();
});

it('refuse le HTML, les configurations inconnues et les cycles', function () {
    OnboardingJourney::publish('onboarding', onboardingDefinition());
    $draftId = $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/drafts')->json('data.id');
    $invalid = onboardingDefinition('<script>alert(1)</script>');
    $invalid[0]['config']['action'] = 'charge_card';
    $invalid[1]['conditions'] = [['step_key' => 'pro_tip', 'operator' => 'equals', 'value' => true]];

    $this->actingAs($this->admin)->patchJson("/api/v1/admin/onboarding-journeys/{$draftId}", ['definition' => $invalid])
        ->assertUnprocessable()->assertJsonValidationErrors([
            'definition.0.config.title', 'definition.0.config', 'definition.1.conditions.0.step_key',
        ]);
});

it('valide explicitement une définition sans la persister', function () {
    $before = OnboardingJourney::count();

    $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/validate', [
        'definition' => onboardingDefinition(),
        'platform' => 'web',
    ])->assertOk()
        ->assertJsonPath('data.valid', true)
        ->assertJsonPath('data.steps_count', 3)
        ->assertJsonPath('data.platforms', ['web', 'mobile']);

    expect(OnboardingJourney::count())->toBe($before);
});

it('refuse une étape sans titre et une condition éditoriale inatteignable', function () {
    $invalid = onboardingDefinition();
    unset($invalid[0]['config']['title']);
    $invalid[2]['conditions'][0]['value'] = 'unknown_choice';

    $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/validate', [
        'definition' => $invalid,
        'platform' => 'web',
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'definition.0.config.title',
        'definition.2.conditions.0.value',
    ]);
});

it('refuse de modifier une version publiée', function () {
    $published = OnboardingJourney::publish('onboarding', onboardingDefinition());
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/onboarding-journeys/{$published->id}", [
        'definition' => onboardingDefinition('Altérée'),
    ])->assertUnprocessable()->assertJsonValidationErrors('journey');
    expect($published->fresh()->definition[0]['config']['title'])->toBe('Bienvenue sur Mibeko');
});

it('prévisualise les plateformes, conditions et anciennes capacités sans écrire', function () {
    $beforeJourneys = OnboardingJourney::count();
    $beforeEnrollments = OnboardingEnrollment::count();
    $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/preview', [
        'definition' => onboardingDefinition(), 'platform' => 'web',
        'known_step_types' => ['welcome', 'single_choice'], 'answers' => ['usage_context' => 'professional'],
    ])->assertOk()->assertJsonPath('data.writes_user_data', false)->assertJsonPath('data.uses_ai', false)
        ->assertJsonPath('data.steps.2.key', 'pro_tip')->assertJsonPath('data.steps.2.supported', false);

    expect(OnboardingJourney::count())->toBe($beforeJourneys)
        ->and(OnboardingEnrollment::count())->toBe($beforeEnrollments);
});

it('publie atomiquement et laisse les inscriptions existantes sur leur version', function () {
    $versionOne = OnboardingJourney::publish('onboarding', onboardingDefinition());
    $existingUser = User::factory()->create();
    $this->actingAs($existingUser)->getJson('/api/v1/onboarding/journey?platform=web')->assertOk();
    $draftId = $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/drafts')->json('data.id');
    $this->actingAs($this->admin)->patchJson("/api/v1/admin/onboarding-journeys/{$draftId}", [
        'definition' => onboardingDefinition('Version 2'),
    ])->assertOk();
    auth()->forgetGuards();
    $adminToken = $this->admin->createToken('onboarding-publication')->plainTextToken;
    $this->postJson("/api/v1/admin/onboarding-journeys/{$draftId}/publish", [], [
        'Authorization' => "Bearer {$adminToken}",
    ])
        ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.is_active', true);

    expect(OnboardingJourney::where('is_active', true)->count())->toBe(1)
        ->and($versionOne->fresh()->is_active)->toBeFalse()
        ->and(OnboardingEnrollment::where('user_id', $existingUser->id)->value('journey_id'))->toBe($versionOne->id)
        ->and(Audit::query()->where('auditable_type', OnboardingJourney::class)
            ->where('auditable_id', $draftId)->where('event', 'updated')
            ->where('user_id', $this->admin->id)->exists())->toBeTrue();
    $newUser = User::factory()->create();
    $this->actingAs($newUser)->getJson('/api/v1/onboarding/journey?platform=web')
        ->assertJsonPath('data.journey.version', 2)->assertJsonPath('data.journey.steps.0.config.title', 'Version 2');
});

it('restaure une ancienne définition dans une nouvelle version sans effacer les progressions', function () {
    $versionOne = OnboardingJourney::publish('onboarding', onboardingDefinition('Version 1'));
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/api/v1/onboarding/journey?platform=web')->assertOk();
    OnboardingJourney::publish('onboarding', onboardingDefinition('Version 2'));
    $this->actingAs($this->admin)->postJson("/api/v1/admin/onboarding-journeys/{$versionOne->id}/rollback")
        ->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.definition.0.config.title', 'Version 1');

    expect(OnboardingEnrollment::where('user_id', $user->id)->value('journey_id'))->toBe($versionOne->id)
        ->and(OnboardingJourney::where('is_active', true)->count())->toBe(1);
});

it('archive un brouillon mais refuse d’archiver la version active', function () {
    $active = OnboardingJourney::publish('onboarding', onboardingDefinition());
    $draftId = $this->actingAs($this->admin)->postJson('/api/v1/admin/onboarding-journeys/drafts')->json('data.id');
    $this->actingAs($this->admin)->postJson("/api/v1/admin/onboarding-journeys/{$draftId}/archive")
        ->assertOk()->assertJsonPath('data.status', 'archived');
    $this->actingAs($this->admin)->postJson("/api/v1/admin/onboarding-journeys/{$active->id}/archive")
        ->assertUnprocessable()->assertJsonValidationErrors('journey');
});
