<?php

use App\Exceptions\InsufficientCreditsException;
use App\Models\AiUsageLog;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\CreditLedger;
use Illuminate\Support\Str;

/**
 * mibeko-dashboard#66 : grand livre en ajout seul, solde dérivé, consommation
 * atomique. Construit par dérogation à « ventes manuelles d'abord »
 * (`docs/decisions.md`, 04/09/2026).
 */
function openRawPgConnection(): PDO
{
    $config = config('database.connections.pgsql');

    return new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
    );
}

it('dérive le solde en sommant les écritures, sans jamais le stocker', function () {
    $user = User::factory()->create();
    $ledger = new CreditLedger;

    $ledger->purchase($user, 10, 'achat initial');
    $ledger->consume($user, 3, 'question IA');
    $ledger->correction($user, -2, 'reprise sur erreur de facturation', $user);

    expect($ledger->balance($user))->toBe(5);
    expect(CreditLedgerEntry::where('user_id', $user->id)->count())->toBe(3);
});

it('refuse une consommation si le solde est insuffisant, sans écrire de ligne', function () {
    $user = User::factory()->create();
    $ledger = new CreditLedger;

    $ledger->purchase($user, 2);

    expect(fn () => $ledger->consume($user, 5, 'question IA'))
        ->toThrow(InsufficientCreditsException::class);

    expect($ledger->balance($user))->toBe(2);
    expect(CreditLedgerEntry::where('user_id', $user->id)->where('type', CreditLedgerEntry::TYPE_CONSUMPTION)->count())->toBe(0);
});

it('valide les montants aux limites du service : achat et consommation positifs, correction non nulle', function () {
    $user = User::factory()->create();
    $ledger = new CreditLedger;

    expect(fn () => $ledger->purchase($user, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $ledger->consume($user, -1, 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $ledger->correction($user, 0, 'x', $user))->toThrow(InvalidArgumentException::class);
});

it('permet de réconcilier une consommation avec le journal d\'usage IA qui l\'a déclenchée', function () {
    $user = User::factory()->create();
    $ledger = new CreditLedger;
    $ledger->purchase($user, 10, 'achat initial');

    $log = AiUsageLog::create([
        'user_id' => $user->id,
        'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS,
        'provider' => 'mistral',
        'model' => 'mistral-large-latest',
        'tokens_input' => 1200,
        'tokens_output' => 300,
    ]);

    $entry = $ledger->consume($user, 1, 'question IA', $log->id);

    expect($entry->reference_id)->toBe($log->id);
    expect(AiUsageLog::find($entry->reference_id)->id)->toBe($log->id);
});

it(
    'le verrou consultatif Postgres interdit à deux sessions de tenir la même clé en même temps '.
    '— le mécanisme exact dont CreditLedger::consume() dépend pour rester atomique sous deux requêtes simultanées',
    function () {
        $userId = (string) Str::uuid();

        $connA = openRawPgConnection();
        $connB = openRawPgConnection();

        $hashStmt = $connA->prepare('select hashtextextended(?, 0) as k');
        $hashStmt->execute([$userId]);
        $lockKey = (int) $hashStmt->fetchColumn();

        // A prend le verrou et ne le relâche pas encore.
        $connA->beginTransaction();
        $connA->prepare('select pg_advisory_xact_lock(?)')->execute([$lockKey]);

        // B ne peut pas l'obtenir tant que A n'a ni commité ni annulé.
        $connB->beginTransaction();
        $tryB = $connB->prepare('select pg_try_advisory_xact_lock(?) as acquired');
        $tryB->execute([$lockKey]);
        expect($tryB->fetchColumn())->toBeFalsy();
        $connB->commit();

        // A libère en committant : B peut alors l'obtenir.
        $connA->commit();

        $connB->beginTransaction();
        $tryB2 = $connB->prepare('select pg_try_advisory_xact_lock(?) as acquired');
        $tryB2->execute([$lockKey]);
        expect($tryB2->fetchColumn())->toBeTruthy();
        $connB->commit();
    }
);
