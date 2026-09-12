<?php

use App\Models\OnboardingEnrollment;
use App\Models\OnboardingJourney;
use App\Models\OnboardingStepProgress;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * mibeko-dashboard#136 — tests de concurrence, façon
 * `tests/Feature/PublicationConcurrencyTest.php`.
 *
 * `RefreshDatabase` enveloppe chaque test dans une transaction sur la
 * connexion Laravel : une SECONDE connexion PDO brute ne voit pas les
 * lignes non commitées de cette transaction (MVCC Postgres) — le test du
 * primitif brut insère donc directement via PDO (autocommit), hors de
 * toute transaction Eloquent, comme le fait déjà `PublicationConcurrencyTest`.
 *
 * Noms de fonctions volontairement distincts de ceux de
 * `PublicationConcurrencyTest.php`/`CreditLedgerTest.php` (`openRawOnboarding*`,
 * `sqlOnboarding*`) : ces fichiers coexistent dans le même run de suite, une
 * redéclaration de fonction PHP identique serait une erreur fatale.
 */
beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);

    OnboardingJourney::publish('onboarding', [
        ['key' => 'usage_context', 'type' => OnboardingJourney::TYPE_SINGLE_CHOICE, 'scope' => 'common', 'binding' => OnboardingJourney::BINDING_USAGE_CONTEXT, 'config' => ['options' => [['code' => 'personal', 'label_key' => 'p'], ['code' => 'professional', 'label_key' => 'pr']]], 'conditions' => []],
    ]);
});

function openRawOnboardingConnection(): PDO
{
    $config = config('database.connections.pgsql');

    return new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
    );
}

function sqlOnboardingEmisPendant(callable $action): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    $action();

    return $sql;
}

it(
    'SELECT … FOR UPDATE interdit à une seconde session de verrouiller la même ligne '.
    'onboarding_step_progress — le mécanisme dont OnboardingController dépend',
    function () {
        $connA = openRawOnboardingConnection();
        $connB = openRawOnboardingConnection();

        $journeyId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        $enrollmentId = (string) Str::uuid();
        $progressId = (string) Str::uuid();

        // Insérées et commitées hors de la transaction de test (autocommit
        // PDO), donc visibles des deux connexions brutes ci-dessous.
        $connA->prepare('insert into onboarding_journeys (id, key, version, status, is_active, definition, created_at, updated_at) values (?, ?, 999, ?, false, ?, now(), now())')
            ->execute([$journeyId, 'onboarding-test-concurrency', 'published', '[]']);
        $connA->prepare('insert into users (id, name, email, password, created_at, updated_at) values (?, ?, ?, ?, now(), now())')
            ->execute([$userId, 'Concurrency Test', "concurrency-{$userId}@example.test", 'x']);
        $connA->prepare('insert into onboarding_enrollments (id, user_id, journey_id, journey_key, status, replay_count, created_at, updated_at) values (?, ?, ?, ?, ?, 0, now(), now())')
            ->execute([$enrollmentId, $userId, $journeyId, 'onboarding-test-concurrency', 'not_started']);
        $connA->prepare('insert into onboarding_step_progress (id, enrollment_id, step_key, created_at, updated_at) values (?, ?, ?, now(), now())')
            ->execute([$progressId, $enrollmentId, 'usage_context']);

        try {
            $connA->beginTransaction();
            $connA->prepare('select id from onboarding_step_progress where id = ? for update')->execute([$progressId]);

            $connB->beginTransaction();
            $connB->exec('set local lock_timeout = 200');

            expect(fn () => $connB
                ->prepare('select id from onboarding_step_progress where id = ? for update nowait')
                ->execute([$progressId])
            )->toThrow(PDOException::class);

            $connB->rollBack();

            $connA->commit();

            $connB->beginTransaction();
            $connB->prepare('select id from onboarding_step_progress where id = ? for update nowait')->execute([$progressId]);
            $connB->commit();
        } finally {
            // Lignes réellement commitées : le rollback de RefreshDatabase
            // en fin de test ne les nettoiera pas.
            $connA->exec('delete from onboarding_step_progress where id = '.$connA->quote($progressId));
            $connA->exec('delete from onboarding_enrollments where id = '.$connA->quote($enrollmentId));
            $connA->exec('delete from users where id = '.$connA->quote($userId));
            $connA->exec('delete from onboarding_journeys where id = '.$connA->quote($journeyId));
        }
    }
);

it('le contrôleur verrouille bien la ligne d\'étape lors d\'un PATCH', function () {
    $user = User::factory()->create();

    $sql = sqlOnboardingEmisPendant(fn () => $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ])->assertOk());

    expect(collect($sql)->contains(fn ($s) => str_contains($s, 'for update')))->toBeTrue();
});

it('deux écritures avec des client_updated_at distincts convergent de façon déterministe vers la plus récente', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'personal', 'client_mutation_id' => 'a', 'client_updated_at' => 5000, 'platform' => 'web',
    ])->assertOk();

    // Une seconde écriture, horodatée plus tôt (réseau en retard) : ignorée.
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'answer', 'value' => 'professional', 'client_mutation_id' => 'b', 'client_updated_at' => 4000, 'platform' => 'web',
    ])->assertOk();

    expect(OnboardingStepProgress::where('step_key', 'usage_context')->first()->value)->toBe('personal');
});

it('deux appels /replay avec le même client_mutation_id n\'incrémentent replay_count qu\'une fois', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->patchJson('/api/v1/onboarding/steps/usage_context', [
        'action' => 'skip', 'client_mutation_id' => 'm1', 'client_updated_at' => 1000, 'platform' => 'web',
    ]);

    $this->actingAs($user)->postJson('/api/v1/onboarding/replay', ['client_mutation_id' => 'r1'])->assertOk();
    $this->actingAs($user)->postJson('/api/v1/onboarding/replay', ['client_mutation_id' => 'r1'])->assertOk();

    expect(OnboardingEnrollment::first()->fresh()->replay_count)->toBe(1);
});
