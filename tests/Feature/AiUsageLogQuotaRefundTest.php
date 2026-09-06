<?php

use App\Ai\AiUsageLogger;
use App\Ai\AiUserQuotaTier;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Usage;
use Spatie\Permission\Models\Role;

/**
 * mibeko-dashboard#99 : le limiteur `ai_assistant` compte une question dès
 * qu'elle entre dans l'application (hit avant le contrôleur), pas quand une
 * réponse est réellement livrée. L'incident `AI_ASSISTANT_FAILOVER` du
 * 05/09/2026 en a fait la preuve en conditions réelles : 17 échecs
 * fournisseur ont chacun décompté une question à l'utilisateur.
 *
 * `simulateAiThrottleHits()` est définie globalement par AiUsageLogTest.php
 * (Pest charge tous les fichiers de tests dans le même espace de noms).
 */
it('rembourse le quota gratuit après un échec fournisseur', function () {
    $user = User::factory()->create();
    $key = AiUserQuotaTier::cacheKey($user, 'month');

    // Une question posée : le hit que le limiteur aurait fait avant le
    // contrôleur, simulé directement pour isoler la logique de remboursement.
    RateLimiter::hit($key, 30 * 86400);
    expect(RateLimiter::attempts($key))->toBe(1);

    app(AiUsageLogger::class)->error($user, 'assistant/chat', exception: new RuntimeException('Le fournisseur a refusé la connexion.'));

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('rembourse le quota Pro (portée jour) après un échec fournisseur', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('user_pro'));
    $key = AiUserQuotaTier::cacheKey($user, 'day');

    RateLimiter::hit($key, 86400);

    app(AiUsageLogger::class)->error($user, 'assistant/chat', exception: new RuntimeException('Panne fournisseur.'));

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('rembourse le quota quand la route n\'a jamais atteint le fournisseur (no_content)', function () {
    $user = User::factory()->create();
    $key = AiUserQuotaTier::cacheKey($user, 'month');

    RateLimiter::hit($key, 30 * 86400);

    app(AiUsageLogger::class)->noContent($user, 'library/synthesis');

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('ne rembourse rien pour une réponse réellement livrée', function () {
    $user = User::factory()->create();
    $key = AiUserQuotaTier::cacheKey($user, 'month');

    RateLimiter::hit($key, 30 * 86400);

    app(AiUsageLogger::class)->success(
        $user,
        'assistant/chat',
        'mistral',
        'mistral-large-latest',
        new Usage(promptTokens: 100, completionTokens: 20),
    );

    expect(RateLimiter::attempts($key))->toBe(1);
});

it('ne rembourse rien pour une réponse servie depuis le cache', function () {
    $user = User::factory()->create();
    $key = AiUserQuotaTier::cacheKey($user, 'month');

    RateLimiter::hit($key, 30 * 86400);

    app(AiUsageLogger::class)->cached($user, 'assistant/chat');

    expect(RateLimiter::attempts($key))->toBe(1);
});

it('ne touche jamais le compteur d\'une requête déjà couverte par un crédit', function () {
    $user = User::factory()->create();
    $key = AiUserQuotaTier::cacheKey($user, 'month');

    // Aucun hit : une requête couverte par un crédit n'entre jamais dans ce
    // compteur (`AppServiceProvider` omet le Limit de fond quand un crédit
    // couvre la requête) — ce test prouve que le remboursement ne le fait
    // pas descendre sous zéro par erreur.
    expect(RateLimiter::attempts($key))->toBe(0);

    app(AiUsageLogger::class)->error(
        $user,
        'assistant/chat',
        exception: new RuntimeException('Panne fournisseur.'),
        id: (string) Str::orderedUuid(),
    );

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('ne rembourse rien pour un refus de quota (429), qui n\'a jamais fait bouger le compteur', function () {
    $user = User::factory()->create();
    $key = AiUserQuotaTier::cacheKey($user, 'month');

    app(AiUsageLogger::class)->rateLimited($user, 'assistant/chat');

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('journalise malgré tout le statut d\'échec, remboursement compris', function () {
    $user = User::factory()->create();

    RateLimiter::hit(AiUserQuotaTier::cacheKey($user, 'month'), 30 * 86400);

    $log = app(AiUsageLogger::class)->error($user, 'assistant/chat', exception: new RuntimeException('Panne.'));

    expect($log->status)->toBe(AiUsageLog::STATUS_ERROR);
});
