<?php

use App\Ai\Agents\MibekoIA;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

it('can list conversations for a user', function () {
    $user = User::factory()->create();
    AgentConversation::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/assistant/conversations');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('caches the ai response for identical queries', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MibekoIA::fake([
        'Ceci est une réponse de test qui sera mise en cache.',
    ]);

    // Première requête : doit appeler l'IA et mettre en cache
    $response1 = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quelles sont les conditions de mariage ?',
    ]);

    $response1->assertStatus(200)
        ->assertJson([
            'reply' => 'Ceci est une réponse de test qui sera mise en cache.',
        ]);

    MibekoIA::assertPrompted('Quelles sont les conditions de mariage ?');

    // Vérifier que la réponse est dans le cache (clé = message normalisé + mode + références)
    $normalizedMessage = 'quelles sont les conditions de mariage'; // sans point d'interrogation et espaces en trop
    $cacheKey = 'ai_response_'.md5($normalizedMessage.'|concise|');
    expect(Cache::has($cacheKey))->toBeTrue();

    // Deuxième requête identique : doit utiliser le cache
    $response2 = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quelles sont les conditions de mariage ?',
    ]);

    $response2->assertStatus(200)
        ->assertJson([
            'reply' => 'Ceci est une réponse de test qui sera mise en cache.',
            'cached' => true,
        ]);

    // Une nouvelle conversation a été créée même si le cache est utilisé
    expect(AgentConversation::where('user_id', $user->id)->count())->toBe(2);
});

it('can retrieve a conversation details', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/assistant/conversations/{$conversation->id}");

    $response->assertStatus(200)
        ->assertJsonPath('id', $conversation->id);
});

it('returns slim history payloads without tool internals', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);

    $base = [
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'agent' => MibekoIA::class,
        'attachments' => [],
        'tool_calls' => [],
        'usage' => [],
        'meta' => [],
    ];

    // created_at n'est pas mass-assignable : on le fixe après création pour
    // garantir un ordre chronologique stable.
    $createMessageAt = function (array $attributes, $createdAt) use ($base) {
        $message = AgentConversationMessage::create(array_merge($base, $attributes));
        $message->created_at = $createdAt;
        $message->save();

        return $message;
    };

    $createMessageAt([
        'role' => 'user',
        'content' => 'Question ?',
        'tool_results' => [],
    ], now()->subMinute());

    // Tour « appel d'outil » sans texte : ne doit pas produire de bulle vide.
    $createMessageAt([
        'role' => 'assistant',
        'content' => '',
        'tool_results' => [['name' => 'SearchLegalDatabase', 'result' => 'contenu très lourd']],
    ], now()->subSeconds(30));

    $createMessageAt([
        'role' => 'assistant',
        'content' => 'Réponse [1].',
        'tool_results' => [],
        'meta' => ['sources' => [['id' => 'a1', 'number' => '12']]],
    ], now());

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/assistant/conversations/{$conversation->id}");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.content', 'Question ?')
        ->assertJsonPath('messages.1.content', 'Réponse [1].')
        ->assertJsonPath('messages.1.meta.sources.0.id', 'a1')
        ->assertJsonMissingPath('messages.0.tool_results')
        ->assertJsonMissingPath('messages.1.tool_results');
});

it('orders a same-second exchange by id so the question precedes the answer', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);

    // created_at est tronqué à la seconde : on reproduit fidèlement le bug en
    // donnant à la question ET à la réponse le même horodatage. La réponse est
    // insérée EN PREMIER avec un id (UUID v7) plus grand : un tri par created_at
    // seul (ex æquo) la ferait remonter avant la question. Seul le départage par
    // id rétablit l'ordre attendu.
    $sameSecond = now()->startOfSecond();

    $base = [
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'agent' => MibekoIA::class,
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ];

    $createWithId = function (string $id, array $attributes) use ($base, $sameSecond) {
        $message = new AgentConversationMessage;
        $message->id = $id; // pré-positionné : HasUuids ne le régénère pas.
        $message->forceFill(array_merge($base, $attributes));
        $message->save();
        $message->created_at = $sameSecond;
        $message->save();
    };

    $createWithId('019f0000-0000-7000-8000-000000000002', [
        'role' => 'assistant',
        'content' => 'La réponse.',
    ]);
    $createWithId('019f0000-0000-7000-8000-000000000001', [
        'role' => 'user',
        'content' => 'La question ?',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/assistant/conversations/{$conversation->id}");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.content', 'La question ?')
        ->assertJsonPath('messages.1.content', 'La réponse.');
});

it('normalizes legacy double-encoded message meta', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);

    // Reproduit l'ancien bug : meta JSON pré-encodé dans une colonne castée
    // array → le cast restitue une chaîne au lieu d'un objet.
    AgentConversationMessage::create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'agent' => MibekoIA::class,
        'role' => 'assistant',
        'content' => 'Réponse historique [1].',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => json_encode(['sources' => [['id' => 'a9', 'number' => '7']], 'cached' => true]),
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/assistant/conversations/{$conversation->id}");

    $response->assertStatus(200)
        ->assertJsonPath('messages.0.meta.sources.0.id', 'a9')
        ->assertJsonPath('messages.0.meta.cached', true);
});

it('lists conversations with slim columns only', function () {
    $user = User::factory()->create();
    AgentConversation::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/assistant/conversations');

    $response->assertStatus(200);
    expect(array_keys($response->json('data.0')))
        ->toBe(['id', 'title', 'created_at', 'updated_at']);
});

it('can chat with the assistant', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MibekoIA::fake([
        'Voici la réponse de Mibeko IA.',
    ]);

    $response = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Bonjour',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'reply' => 'Voici la réponse de Mibeko IA.',
        ]);

    MibekoIA::assertPrompted('Bonjour');

    // Check that a conversation was created
    expect(AgentConversation::where('user_id', $user->id)->count())->toBe(1);
});

it('rejects an invalid response mode', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Bonjour',
        'mode' => 'verbose',
    ])->assertStatus(422)->assertJsonValidationErrors(['mode']);
});

it('answers in analysis mode and does not reuse the concise cache', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MibekoIA::fake([
        'Réponse courte.',
    ]);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quelles sont les conditions de mariage ?',
    ])->assertStatus(200)->assertJson(['reply' => 'Réponse courte.']);

    // Même question en mode analyse : la clé de cache diffère, l'IA est rappelée
    // au lieu de resservir la réponse concise mise en cache.
    $response = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quelles sont les conditions de mariage ?',
        'mode' => 'analysis',
    ]);

    $response->assertStatus(200)->assertJsonMissingPath('cached');
    expect($response->json('reply'))->not->toBe('Réponse courte.');
});

it('stores pinned references on the user message and scopes the search tool', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Code du travail',
        'curation_status' => 'published',
    ]);

    MibekoIA::fake([
        'Réponse ciblée sur le Code du travail.',
    ]);

    $response = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quel est le délai de préavis ?',
        'references' => [
            ['id' => $document->id, 'type' => 'document'],
        ],
    ]);

    $response->assertStatus(200);

    $userMessage = AgentConversationMessage::where('user_id', $user->id)
        ->where('role', 'user')
        ->first();

    expect($userMessage)->not->toBeNull()
        ->and($userMessage->meta['references'] ?? [])->toBe([
            ['id' => $document->id, 'title' => 'Code du travail'],
        ]);
});

it('silently drops unpublished documents from pinned references', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $draft = LegalDocument::factory()->create([
        'curation_status' => 'draft',
    ]);

    MibekoIA::fake(['Réponse.']);

    $response = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Question sans périmètre valide',
        'references' => [
            ['id' => $draft->id, 'type' => 'document'],
        ],
    ]);

    $response->assertStatus(200);

    $userMessage = AgentConversationMessage::where('user_id', $user->id)
        ->where('role', 'user')
        ->first();

    expect($userMessage->meta['references'] ?? null)->toBeNull();
});

it('lists published documents as pinnable references', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    LegalDocument::factory()->create([
        'titre_officiel' => 'Code du travail',
        'curation_status' => 'published',
    ]);
    LegalDocument::factory()->create([
        'titre_officiel' => 'Code pénal (brouillon)',
        'curation_status' => 'draft',
    ]);

    $response = $this->getJson('/api/v1/assistant/references?q=code');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Code du travail');
});
