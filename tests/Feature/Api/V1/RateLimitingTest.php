<?php

use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Simule N passages déjà consommés sur un limiteur nommé, tel que le
 * middleware `throttle` les compte (clé = md5(nom du limiteur + clé du Limit)).
 */
function simulateThrottleHits(string $limiterName, string $limitKey, int $hits, int $decaySeconds): void
{
    $key = md5($limiterName.$limitKey);

    for ($i = 0; $i < $hits; $i++) {
        RateLimiter::hit($key, $decaySeconds);
    }
}

it('applies the api rate limiter and returns correct headers', function () {
    $response = $this->getJson('/api/v1/home');

    $response->assertStatus(200);
    $response->assertHeader('X-RateLimit-Limit', 2); // In testing it's 2
    $response->assertHeader('X-RateLimit-Remaining', 1);
});

it('throttles requests when limit is exceeded', function () {
    // Request 1: OK
    $this->getJson('/api/v1/home')->assertStatus(200);

    // Request 2: OK
    $this->getJson('/api/v1/home')->assertStatus(200);

    // Request 3: Throttled (429)
    $response = $this->getJson('/api/v1/home');
    $response->assertStatus(429);
    $response->assertHeader('Retry-After');
});

it('limite les tentatives de connexion par combinaison email + IP', function () {
    // 5 tentatives déjà consommées pour cet email depuis cette IP…
    simulateThrottleHits('login', 'cible@mibeko.cg|127.0.0.1', 5, 60);

    // …la suivante est refusée avec un message clair (l'email est normalisé
    // en minuscules par le limiteur).
    $this->postJson('/api/v1/login', [
        'email' => 'Cible@Mibeko.cg',
        'password' => 'mauvais-mot-de-passe',
        'device_name' => 'test-device',
    ])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Trop de tentatives de connexion. Réessayez dans une minute.');

    // Un autre compte depuis la même IP n'est pas pénalisé : la limite est
    // par email + IP, pas par IP seule.
    $this->postJson('/api/v1/login', [
        'email' => 'autre@mibeko.cg',
        'password' => 'mauvais-mot-de-passe',
        'device_name' => 'test-device',
    ])->assertStatus(422);
});

it('plafonne les signalements publics anonymes par IP (quota minute)', function () {
    $document = LegalDocument::factory()->create();

    // 5 signalements déjà consommés depuis cette IP dans la minute…
    simulateThrottleHits('reports', 'minute:127.0.0.1', 5, 60);

    // …le suivant est refusé avant même le quota global `api`.
    $this->postJson('/api/v1/reports', [
        'document_id' => $document->id,
        'type_probleme' => 'erreur',
    ])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Trop de signalements envoyés. Réessayez plus tard.');
});

it('plafonne les signalements publics anonymes par IP (quota journalier)', function () {
    $document = LegalDocument::factory()->create();

    // Quota journalier consommé (le quota minute reste vierge).
    simulateThrottleHits('reports', 'day:127.0.0.1', 30, 86400);

    $this->postJson('/api/v1/reports', [
        'document_id' => $document->id,
        'type_probleme' => 'erreur',
    ])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Trop de signalements envoyés. Réessayez plus tard.');
});

it('plafonne les requêtes IA mensuelles d\'un utilisateur standard (mibeko-dashboard#62)', function () {
    $user = User::factory()->create();

    // Quota mensuel consommé (la limite par minute reste vierge). La fenêtre
    // est glissante sur 30 jours (Limit::perDay(..., 30)), donc la même clé
    // de cache que le décompte journalier — seule la durée de décroissance
    // change, simulée ici en 30 jours pour matcher `->by('month:'.$id)`.
    simulateThrottleHits('ai_assistant', 'month:'.$user->id, config('ai.quotas.standard.per_month'), 30 * 86400);

    $this->actingAs($user)
        ->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'AI_RATE_LIMITED')
        ->assertJsonPath('scope', 'month')
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'Plafond mensuel') && str_contains($message, 'jour'));
});

it('user_pro garde un plafond journalier distinct du palier gratuit', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('user_pro'));

    simulateThrottleHits('ai_assistant', 'day:'.$user->id, config('ai.quotas.user_pro.per_day'), 86400);

    $this->actingAs($user)
        ->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Plafond journalier de requêtes IA atteint. Réessayez demain.')
        ->assertJsonPath('scope', 'day');
});

it('renvoie un 429 IA par minute neutre, sans incitation à l\'achat (App Store 3.1.1)', function () {
    $user = User::factory()->create();

    // Quota minute consommé (le quota journalier reste vierge).
    simulateThrottleHits('ai_assistant', 'minute:'.$user->id, config('ai.quotas.standard.per_minute'), 60);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', 'Limite temporaire de requêtes atteinte. Réessayez dans quelques minutes.')
        ->assertJsonPath('code', 'AI_RATE_LIMITED')
        ->assertJsonPath('scope', 'minute');

    // Le message s'affiche tel quel dans le chat de l'app mobile : toute
    // mention d'abonnement serait une incitation à l'achat hors achat in-app
    // (motif de rejet Apple 3.1.1).
    expect($response->getContent())
        ->not->toContain('abonnement')
        ->not->toContain('Passez à');
});

it('plafonne aussi les requêtes IA journalières des administrateurs', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin'));

    // L'admin n'a plus de passe-droit illimité : un jeton admin compromis ne
    // doit pas pouvoir générer une facture LLM sans plafond.
    simulateThrottleHits('ai_assistant', 'day:'.$admin->id, config('ai.quotas.admin.per_day'), 86400);

    $this->actingAs($admin)
        ->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Plafond journalier de requêtes IA atteint. Réessayez demain.');
});

it('laisse passer une requête IA sous les plafonds', function () {
    $user = User::factory()->create();

    // Sous les deux plafonds : le middleware laisse passer (le contrôleur
    // valide ensuite la requête — 422 attendu ici, pas 429).
    $this->actingAs($user)
        ->postJson('/api/v1/assistant/chat', [])
        ->assertStatus(422);
});
