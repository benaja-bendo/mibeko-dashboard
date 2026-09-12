<?php

use App\Models\AiUsageLog;
use App\Models\Article;
use App\Models\ProductActivationEvent;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * mibeko-dashboard#137 : événement d'activation produit, idempotent,
 * whitelist stricte, et « fait vérifiable par le serveur » pour l'activation
 * candidate (source ouverte depuis une réponse réellement réussie et citée).
 */
it('refuse un appelant non authentifié', function () {
    $this->postJson('/api/v1/product-events', [])->assertUnauthorized();
});

it('crée un événement search_useful et capture les dimensions côté serveur', function () {
    $user = User::factory()->create();
    $user->mobileProfile()->create(['usage_context' => 'professional']);
    $article = Article::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful',
        'surface' => 'web',
        'reference_id' => $article->id,
        'client_event_id' => 'evt-1',
    ]);

    $response->assertStatus(201)->assertJsonPath('data.event_type', 'search_useful');

    $event = ProductActivationEvent::sole();
    expect($event->usage_context)->toBe('professional');
    expect($event->reference_type)->toBe('article');
    expect($event->surface)->toBe('web');
});

it('déduplique un même client_event_id rejoué depuis deux surfaces', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful', 'surface' => 'web',
        'reference_id' => $article->id, 'client_event_id' => 'evt-shared',
    ])->assertStatus(201);

    $firstCreatedAt = ProductActivationEvent::sole()->created_at;

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful', 'surface' => 'mobile',
        'reference_id' => $article->id, 'client_event_id' => 'evt-shared',
    ])->assertStatus(201);

    expect(ProductActivationEvent::count())->toBe(1);
    expect(ProductActivationEvent::sole()->created_at->eq($firstCreatedAt))->toBeTrue();
    expect(ProductActivationEvent::sole()->surface)->toBe('web');
});

it('rejette une clé hors liste blanche', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful', 'surface' => 'web',
        'reference_id' => $article->id, 'client_event_id' => 'evt-1',
        'usage_context' => 'professional',
    ])->assertStatus(422)->assertJsonValidationErrors('_unknown');
});

it('rejette un event_type ou une surface hors énumération', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'inconnu', 'surface' => 'web',
        'reference_id' => $article->id, 'client_event_id' => 'evt-1',
    ])->assertStatus(422)->assertJsonValidationErrors('event_type');

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful', 'surface' => 'desktop',
        'reference_id' => $article->id, 'client_event_id' => 'evt-2',
    ])->assertStatus(422)->assertJsonValidationErrors('surface');
});

it('accepte source_opened_after_answer pour une réponse réussie et citée du compte', function () {
    $user = User::factory()->create();
    $log = AiUsageLog::create([
        'user_id' => $user->id, 'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS, 'has_citation' => true,
    ]);

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'source_opened_after_answer', 'surface' => 'mobile',
        'reference_id' => $log->id, 'client_event_id' => 'evt-1',
    ])->assertStatus(201);

    expect(ProductActivationEvent::sole()->reference_type)->toBe('ai_usage_log');
});

it('refuse source_opened_after_answer sur une réponse sans citation', function () {
    $user = User::factory()->create();
    $log = AiUsageLog::create([
        'user_id' => $user->id, 'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS, 'has_citation' => false,
    ]);

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'source_opened_after_answer', 'surface' => 'web',
        'reference_id' => $log->id, 'client_event_id' => 'evt-1',
    ])->assertStatus(422)->assertJsonValidationErrors('reference_id');
});

it('refuse source_opened_after_answer sur un flux en échec', function () {
    $user = User::factory()->create();
    $log = AiUsageLog::create([
        'user_id' => $user->id, 'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_ERROR,
    ]);

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'source_opened_after_answer', 'surface' => 'web',
        'reference_id' => $log->id, 'client_event_id' => 'evt-1',
    ])->assertStatus(422)->assertJsonValidationErrors('reference_id');
});

it('ne laisse pas un compte pointer vers le ai_usage_log d\'un autre compte', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $log = AiUsageLog::create([
        'user_id' => $owner->id, 'route' => 'assistant/chat',
        'status' => AiUsageLog::STATUS_SUCCESS, 'has_citation' => true,
    ]);

    $this->actingAs($attacker)->postJson('/api/v1/product-events', [
        'event_type' => 'source_opened_after_answer', 'surface' => 'web',
        'reference_id' => $log->id, 'client_event_id' => 'evt-1',
    ])->assertStatus(422)->assertJsonValidationErrors('reference_id');

    expect(ProductActivationEvent::count())->toBe(0);
});

it('refuse search_useful sur un article inexistant ou supprimé', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful', 'surface' => 'web',
        'reference_id' => (string) Str::uuid(), 'client_event_id' => 'evt-1',
    ])->assertStatus(422)->assertJsonValidationErrors('reference_id');

    $deleted = Article::factory()->create();
    $deleted->delete();

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful', 'surface' => 'web',
        'reference_id' => $deleted->id, 'client_event_id' => 'evt-2',
    ])->assertStatus(422)->assertJsonValidationErrors('reference_id');
});
