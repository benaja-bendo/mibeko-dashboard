<?php

use App\Ai\Agents\MibekoIA;
use App\Models\AiUsageLog;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\CreditLedger;
use Spatie\Permission\Models\Role;

/**
 * mibeko-dashboard#83 : au-delà du quota gratuit, un crédit couvre la
 * requête avant de renvoyer un 429 — jamais pour admin (staff, pas un
 * client). Voir la fondation en ajout seul du grand livre : CreditLedgerTest.
 *
 * `simulateAiThrottleHits()` est définie globalement par AiUsageLogTest.php
 * (Pest charge tous les fichiers de tests dans le même espace de noms) —
 * ne pas la redéclarer ici.
 */
it('consomme un crédit et laisse passer une requête IA au-delà du quota gratuit', function () {
    $user = User::factory()->create();
    simulateAiThrottleHits('month:'.$user->id, config('ai.quotas.standard.per_month'), 30 * 86400);
    (new CreditLedger)->purchase($user, 10, 'achat de test');

    MibekoIA::fake(['Réponse.']);

    $this->actingAs($user)
        ->postJson('/api/v1/assistant/chat', ['message' => 'Une vraie question'])
        ->assertOk();

    $log = AiUsageLog::where('user_id', $user->id)->sole();
    expect($log->status)->toBe(AiUsageLog::STATUS_SUCCESS);

    $entry = CreditLedgerEntry::where('user_id', $user->id)
        ->where('type', CreditLedgerEntry::TYPE_CONSUMPTION)
        ->sole();
    expect($entry->amount)->toBe(-1);
    expect($entry->reference_id)->toBe($log->id);

    expect((new CreditLedger)->balance($user))->toBe(9);
});

it('refuse toujours en 429, message à l\'appui, si le quota est dépassé sans crédit', function () {
    $user = User::factory()->create();
    simulateAiThrottleHits('month:'.$user->id, config('ai.quotas.standard.per_month'), 30 * 86400);

    $this->actingAs($user)
        ->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertStatus(429)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'crédit'));

    expect(CreditLedgerEntry::where('user_id', $user->id)->exists())->toBeFalse();
});

it('n\'attribue jamais de secours par crédit à un administrateur', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin'));
    simulateAiThrottleHits('day:'.$admin->id, config('ai.quotas.admin.per_day'), 86400);
    (new CreditLedger)->purchase($admin, 10, 'achat de test');

    $this->actingAs($admin)
        ->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Plafond journalier de requêtes IA atteint. Réessayez demain.');

    // Le solde n'a pas bougé : aucune tentative de consommation, même si un
    // crédit aurait suffi à couvrir la requête.
    expect((new CreditLedger)->balance($admin))->toBe(10);
});

it('expose le vrai solde de crédits dans les entitlements', function () {
    $user = User::factory()->create();
    (new CreditLedger)->purchase($user, 7, 'achat de test');

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.credits', 7);
});
