<?php

use App\Ai\Agents\MibekoIA;
use App\Ai\CorpusVersion;
use App\Jobs\GenerateConversationTitle;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentMessageFeedback;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

it('caps the conversation history replayed to the model', function () {
    // Garde-fou de coût : ne jamais revenir au défaut du package (100), qui
    // rejouerait des dizaines de tours (et leurs extraits) à chaque requête.
    $method = new ReflectionMethod(MibekoIA::class, 'maxConversationMessages');

    expect($method->invoke(new MibekoIA))
        ->toBeLessThanOrEqual(40)
        ->toBeGreaterThanOrEqual(10);
});

it('can list conversations for a user', function () {
    $user = User::factory()->create();
    AgentConversation::factory()->count(3)->create(['user_id' => $user->id])
        ->each(fn (AgentConversation $conversation) => AgentConversationMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
        ]));

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/assistant/conversations');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('hides empty shell conversations from the history list', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $withMessage = AgentConversation::factory()->create(['user_id' => $user->id]);
    AgentConversationMessage::factory()->create([
        'conversation_id' => $withMessage->id,
        'user_id' => $user->id,
    ]);

    // Coquille vide : conversation créée mais échange interrompu avant la
    // persistance du moindre message — elle ne doit pas apparaître.
    AgentConversation::factory()->create(['user_id' => $user->id]);

    $response = $this->getJson('/api/v1/assistant/conversations');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $withMessage->id);
});

it('cascades message deletion when a conversation is deleted', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);
    AgentConversationMessage::factory()->count(2)->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
    ]);

    $this->deleteJson("/api/v1/assistant/conversations/{$conversation->id}")
        ->assertNoContent();

    // Aucun message orphelin ne subsiste après suppression de la conversation.
    expect(AgentConversationMessage::where('conversation_id', $conversation->id)->count())
        ->toBe(0);
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

    // Vérifier que la réponse est dans le cache (clé = message normalisé + mode
    // + références + version du corpus).
    $normalizedMessage = 'quelles sont les conditions de mariage'; // sans point d'interrogation et espaces en trop
    $cacheKey = 'ai_response_'.md5($normalizedMessage.'|concise|'.'|'.CorpusVersion::current());
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

it('invalidates the cached response when the legal corpus changes', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MibekoIA::fake(['Réponse initiale.']);

    $question = ['message' => 'Quels sont les délais de préavis ?'];

    // 1er appel : calcule et met en cache (le hit sans changement de corpus est
    // déjà couvert par le test « caches the ai response »). On reste à 2 appels :
    // le limiteur `api` est à 2/min en test.
    $this->postJson('/api/v1/assistant/chat', $question)
        ->assertStatus(200)
        ->assertJson(['reply' => 'Réponse initiale.']);

    // Le corpus change → la version est bumpée → la clé de cache change.
    CorpusVersion::bump();

    // Appel identique : le cache est manqué (l'IA est rappelée). L'absence du
    // drapeau `cached` prouve qu'on ne resert pas la réponse mémorisée.
    $this->postJson('/api/v1/assistant/chat', $question)
        ->assertStatus(200)
        ->assertJsonMissingPath('cached');
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
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);
    AgentConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
    ]);

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

it('records and updates feedback on an assistant message', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);
    $message = AgentConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
    ]);

    // Pouce haut.
    $this->postJson("/api/v1/assistant/messages/{$message->id}/feedback", ['rating' => 'up'])
        ->assertStatus(200)
        ->assertJsonPath('rating', 'up');

    // Bascule en pouce bas avec commentaire : upsert, jamais de doublon.
    $this->postJson("/api/v1/assistant/messages/{$message->id}/feedback", [
        'rating' => 'down',
        'comment' => 'Réponse imprécise',
    ])->assertStatus(200)->assertJsonPath('rating', 'down');

    expect(AgentMessageFeedback::where('message_id', $message->id)->where('user_id', $user->id)->count())
        ->toBe(1)
        ->and(AgentMessageFeedback::where('message_id', $message->id)->first()->comment)
        ->toBe('Réponse imprécise');
});

it('clears feedback on an assistant message', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);
    $message = AgentConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
    ]);

    $this->postJson("/api/v1/assistant/messages/{$message->id}/feedback", ['rating' => 'up'])
        ->assertStatus(200);

    $this->deleteJson("/api/v1/assistant/messages/{$message->id}/feedback")
        ->assertNoContent();

    expect(AgentMessageFeedback::where('message_id', $message->id)->count())->toBe(0);
});

it('rejects feedback on another user message', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $conversation = AgentConversation::factory()->create(['user_id' => $owner->id]);
    $message = AgentConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($intruder);

    $this->postJson("/api/v1/assistant/messages/{$message->id}/feedback", ['rating' => 'up'])
        ->assertNotFound();

    expect(AgentMessageFeedback::count())->toBe(0);
});

it('validates the feedback rating', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);
    $message = AgentConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
    ]);

    $this->postJson("/api/v1/assistant/messages/{$message->id}/feedback", ['rating' => 'meh'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);
});

it('exposes the current user feedback when showing a conversation', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);

    $question = AgentConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'role' => 'user',
        'content' => 'Ma question ?',
    ]);
    $answer = AgentConversationMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'content' => 'Ma réponse.',
    ]);

    AgentMessageFeedback::create([
        'message_id' => $answer->id,
        'user_id' => $user->id,
        'rating' => 'up',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/assistant/conversations/{$conversation->id}");

    $response->assertStatus(200)
        ->assertJsonPath('messages.0.feedback', null)
        ->assertJsonPath('messages.1.feedback', 'up');
});

it('dispatches AI title generation for a newly created conversation', function () {
    Queue::fake();

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MibekoIA::fake(['Réponse.']);

    // 1er appel : conversation créée par le package (titre IA déjà côté package).
    // 2e appel identique : notre chemin « cache » pré-crée la conversation et
    // dispatche la génération de titre.
    $question = ['message' => 'Question identique sur les délais'];
    $this->postJson('/api/v1/assistant/chat', $question)->assertOk();
    $this->postJson('/api/v1/assistant/chat', $question)->assertOk()->assertJson(['cached' => true]);

    Queue::assertPushed(GenerateConversationTitle::class);
});
