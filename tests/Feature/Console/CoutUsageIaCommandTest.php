<?php

use App\Models\AiUsageLog;
use App\Models\User;

/**
 * `created_at` n'est pas fillable (Eloquent le gère lui-même) : le fixer à une
 * date arbitraire passe par une mise à jour via le query builder, qui ne
 * touche pas aux timestamps automatiques (`UPDATED_AT` est désactivé sur ce
 * modèle).
 */
function logAt(array $attributes, string $createdAt): AiUsageLog
{
    $log = AiUsageLog::create($attributes);
    AiUsageLog::whereKey($log->id)->update(['created_at' => $createdAt]);

    return $log->fresh();
}

it('affiche le coût agrégé par utilisateur pour le mois demandé', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);

    logAt([
        'user_id' => $alice->id,
        'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS,
        'provider' => 'mistral',
        'model' => 'mistral-large-latest',
        'tokens_input' => 1000,
        'tokens_output' => 200,
        'cost_estimated_fcfa' => 2.5,
    ], '2026-06-15 10:00:00');

    logAt([
        'user_id' => $bob->id,
        'route' => 'library/explain',
        'status' => AiUsageLog::STATUS_SUCCESS,
        'cost_estimated_fcfa' => 1,
    ], '2026-06-20 10:00:00');

    // Hors période : ne doit pas apparaître dans le rapport de juin.
    logAt([
        'user_id' => $alice->id,
        'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS,
        'cost_estimated_fcfa' => 99,
    ], '2026-07-01 00:00:00');

    // Chaque fragment attendu ne doit matcher qu'UNE seule ligne de sortie :
    // Mockery n'honore qu'une expectation par appel à doWrite(), donc un
    // fragment qui apparaît sur deux lignes (ex. « 2,50 » à la fois sur la
    // ligne d'Alice et sur un total qui vaudrait aussi 2,50) fait échouer
    // l'assertion suivante même quand la commande est correcte — d'où le
    // montant de Bob volontairement différent de celui d'Alice ci-dessus.
    $this->artisan('mibeko:cout-usage-ia', ['--mois' => '2026-06'])
        ->assertSuccessful()
        ->expectsOutputToContain('Alice')
        ->expectsOutputToContain('Bob')
        ->expectsOutputToContain('Total 2026-06 : 3,50');
});

it('rejette un format de mois invalide', function () {
    $this->artisan('mibeko:cout-usage-ia', ['--mois' => 'juin-2026'])
        ->assertFailed();
});

it('signale les appels réussis sans tarif connu', function () {
    $user = User::factory()->create();

    AiUsageLog::create([
        'user_id' => $user->id,
        'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS,
        'provider' => 'fournisseur-inconnu',
        'tokens_input' => 500,
        'tokens_output' => 50,
        'cost_estimated_fcfa' => null,
        'created_at' => now(),
    ]);

    $this->artisan('mibeko:cout-usage-ia')
        ->assertSuccessful()
        ->expectsOutputToContain('sans tarif connu');
});
