<?php

use App\Models\Article;
use App\Models\ProductActivationEvent;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

/**
 * mibeko-dashboard#137 : l'export RGPD inclut le détail nominatif
 * d'activation du compte courant, exclut celui d'un autre compte, et ne
 * contient aucun texte libre — uniquement des identifiants opaques.
 */
it('inclut les événements d\'activation du compte courant dans l\'export', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful', 'surface' => 'web',
        'reference_id' => $article->id, 'client_event_id' => 'evt-1',
    ])->assertStatus(201);

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['product_activation'])->toHaveCount(1);
    expect($payload['product_activation'][0]['event_type'])->toBe('search_useful');
    expect($payload['product_activation'][0]['reference_id'])->toBe($article->id);
});

it('exclut les événements d\'activation d\'un autre compte', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $article = Article::factory()->create();

    $this->actingAs($userA)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful', 'surface' => 'web',
        'reference_id' => $article->id, 'client_event_id' => 'evt-1',
    ])->assertStatus(201);

    $response = $this->actingAs($userB)->get('/api/v1/profile/export');
    $payload = json_decode($response->streamedContent(), true);

    expect($payload['product_activation'])->toHaveCount(0);
});

it('n\'expose aucun texte libre dans l\'export d\'activation', function () {
    $user = User::factory()->create();
    ProductActivationEvent::create([
        'user_id' => $user->id, 'event_type' => 'search_useful', 'surface' => 'web',
        'reference_type' => 'article', 'reference_id' => Article::factory()->create()->id,
        'client_event_id' => 'evt-1',
    ]);

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $payload = json_decode($response->streamedContent(), true);

    expect(array_keys($payload['product_activation'][0]))
        ->toBe(['event_type', 'surface', 'reference_type', 'reference_id', 'created_at']);
});
