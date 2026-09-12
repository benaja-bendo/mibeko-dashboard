<?php

use App\Models\AiUsageLog;
use App\Models\Device;
use App\Models\Dossier;
use App\Models\DossierEcheance;
use App\Models\LegalDocument;
use App\Models\ProductActivationEvent;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `created_at` n'est pas fillable sur `ai_usage_logs` (Eloquent le gère
 * lui-même, cf. `AiUsageLog`) : le fixer à une date arbitraire passe par une
 * mise à jour via le query builder, comme dans `CoutUsageIaCommandTest`.
 */
function logIaAt(array $attributes, string $createdAt): AiUsageLog
{
    $log = AiUsageLog::create($attributes);
    AiUsageLog::whereKey($log->id)->update(['created_at' => $createdAt]);

    return $log->fresh();
}

/** Même contrainte que `logIaAt()` : `created_at` géré par Eloquent, écrasé par `save()`. */
function utilisateurCreeLe(string $createdAt): User
{
    $user = User::factory()->create();
    User::whereKey($user->id)->update(['created_at' => $createdAt]);

    return $user->fresh();
}

function evenementActivationLe(array $attributes, string $createdAt): ProductActivationEvent
{
    $event = ProductActivationEvent::create($attributes);
    ProductActivationEvent::whereKey($event->id)->update(['created_at' => $createdAt]);

    return $event->fresh();
}

it('refuse une connexion qui n\'est pas déclarée', function () {
    $this->artisan('mibeko:kpis', ['--connection' => 'connexion_inexistante'])
        ->expectsOutputToContain('n\'est pas déclarée')
        ->assertExitCode(1);
});

it('imprime les six sections sur une base sans données', function () {
    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('## Comptes')
        ->expectsOutputToContain('## Assistant IA')
        ->expectsOutputToContain('Aucun usage IA journalisé sur les 6 derniers mois.')
        ->expectsOutputToContain('## Dossiers')
        ->expectsOutputToContain('## Veille (appareils push)')
        ->expectsOutputToContain('Aucun appareil enregistré.')
        ->expectsOutputToContain('## Corpus')
        ->expectsOutputToContain('## Activation produit')
        ->expectsOutputToContain('Aucune cohorte sur les 12 dernières semaines.');
});

it('compte les comptes vivants et leur origine par jeton', function () {
    $mobileActif = User::factory()->create();
    $mobileActif->createToken('Mobile Device');
    PersonalAccessToken::where('tokenable_id', $mobileActif->id)
        ->update(['last_used_at' => now()->subDays(2)]);

    $webInactif = User::factory()->create();
    $webInactif->createToken('mibeko-saas-web');
    PersonalAccessToken::where('tokenable_id', $webInactif->id)
        ->update(['last_used_at' => now()->subDays(60)]);

    User::factory()->create()->delete();

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('Comptes vivants : 2 — actifs 30j : 1 mobile, 0 web.');
});

it('agrège les questions IA réussies par mois et calcule la moyenne du dernier mois', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    logIaAt(['user_id' => $alice->id, 'route' => 'assistant/chat', 'status' => AiUsageLog::STATUS_SUCCESS, 'cost_estimated_fcfa' => 63.4], now()->subMonth()->format('Y-m-15'));
    logIaAt(['user_id' => $bob->id, 'route' => 'assistant/chat', 'status' => AiUsageLog::STATUS_SUCCESS, 'cost_estimated_fcfa' => 63.4], now()->format('Y-m-01'));
    logIaAt(['user_id' => $bob->id, 'route' => 'assistant/chat', 'status' => AiUsageLog::STATUS_ERROR], now()->format('Y-m-02'));

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('Moyenne du dernier mois plein : 1 question(s) par utilisateur actif.');
});

it('ne compte pas une question IA en erreur dans la moyenne mensuelle', function () {
    $unique = User::factory()->create();

    logIaAt(['user_id' => $unique->id, 'route' => 'assistant/chat', 'status' => AiUsageLog::STATUS_ERROR], now()->format('Y-m-01'));

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->doesntExpectOutputToContain('Moyenne du dernier mois plein : 1 question(s) par utilisateur actif.');
});

it('compte les dossiers avec un champ d\'affaire renseigné', function () {
    Dossier::factory()->create(['client_name' => 'Client Test']);
    Dossier::factory()->create(); // classeur sans champ d'affaire

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('Dossiers vivants : 2, dont 1 avec au moins un champ d\'affaire renseigné.');
});

it('compte les échéances à venir', function () {
    $dossier = Dossier::factory()->create();
    DossierEcheance::factory()->for($dossier)->dueInDays(5)->create();
    DossierEcheance::factory()->for($dossier)->create(['due_date' => now()->subDays(5)]);

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('Échéances à venir');
});

it('compte les appareils vus récemment', function () {
    Device::factory()->create(['platform' => 'android']);
    $vieux = Device::factory()->create(['platform' => 'android']);
    Device::whereKey($vieux->id)->update(['updated_at' => now()->subDays(90)]);

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('Appareils enregistrés : 2, dont 1 vus sur 30 jours.');
});

it('compte le corpus publié et non publié', function () {
    LegalDocument::factory()->create(['curation_status' => 'published']);
    LegalDocument::factory()->create(['curation_status' => 'draft']);
    LegalDocument::factory()->create(['curation_status' => 'draft'])->delete();

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('Corpus : 1 publié(s), 1 non publié(s).');
});

// ── Activation produit (mibeko-dashboard#137) ─────────────────────────────

it('affiche 0% avec le dénominateur quand une cohorte n\'a aucun événement', function () {
    User::factory()->count(3)->create();

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('3 compte(s)) : 0% recherche utile, 0% réponse réussie, 0% activation candidate');
});

it('affiche non mesurable pour le retour J+7 d\'une cohorte trop jeune', function () {
    User::factory()->create();

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('Retour J+7 : non mesurable (aucune cohorte mature).');
});

it('compte un retour dans la fenêtre J+7 pour une cohorte mature', function () {
    $user = utilisateurCreeLe(now()->subDays(20)->toDateTimeString());
    $user->createToken('mibeko-saas-web');
    PersonalAccessToken::where('tokenable_id', $user->id)
        ->update(['last_used_at' => now()->subDays(20)->addDays(8)]);

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('Retour J+7 : 100% (sur 1 compte(s) mature(s))');
});

it('ne compte pas une réponse réussie et citée sans source ouverte comme activation candidate', function () {
    $user = User::factory()->create();
    AiUsageLog::create([
        'user_id' => $user->id, 'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS, 'has_citation' => true,
    ]);

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('100% réponse réussie, 0% activation candidate');
});

it('ne compte qu\'une première activation et calcule le délai depuis la plus ancienne', function () {
    $user = utilisateurCreeLe(now()->subDays(5)->toDateTimeString());
    $log = AiUsageLog::create([
        'user_id' => $user->id, 'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS, 'has_citation' => true,
    ]);

    evenementActivationLe([
        'user_id' => $user->id, 'event_type' => 'source_opened_after_answer', 'surface' => 'web',
        'reference_type' => 'ai_usage_log', 'reference_id' => $log->id, 'client_event_id' => 'e1',
    ], now()->subDays(5)->addDays(2)->toDateTimeString());

    evenementActivationLe([
        'user_id' => $user->id, 'event_type' => 'source_opened_after_answer', 'surface' => 'mobile',
        'reference_type' => 'ai_usage_log', 'reference_id' => $log->id, 'client_event_id' => 'e2',
    ], now()->subDays(5)->addDays(4)->toDateTimeString());

    $this->artisan('mibeko:kpis', ['--connection' => config('database.default')])
        ->assertSuccessful()
        ->expectsOutputToContain('100% activation candidate')
        ->expectsOutputToContain('Délai médian jusqu\'à l\'activation candidate : 2.0 jour(s).');
});
