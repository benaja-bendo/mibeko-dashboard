<?php

use App\Ai\Agents\MibekoIA;
use App\Ai\AssistantChatService;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiUsageLog;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Queue::fake();
    config(['ai.default' => 'mistral', 'ai.assistant.providers' => ['mistral'], 'ai.providers.mistral.key' => 'test-only']);
    Sanctum::actingAs(User::factory()->create());
    Http::preventStrayRequests();
});

function providerStream(array $frames): string
{
    return implode('', array_map(fn ($frame) => 'data: '.json_encode($frame)."\n\n", $frames))."data: [DONE]\n\n";
}

it('serves real provider SSE content blocks without a fake agent', function () {
    Http::fake(['*' => Http::response(providerStream([
        ['choices' => [['delta' => ['content' => [['type' => 'text', 'text' => 'Bonjour.']]], 'finish_reason' => 'stop']]],
    ]), 200, ['Content-Type' => 'text/event-stream'])]);
    $response = $this->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour', 'stream' => true]);
    $body = $response->streamedContent();
    expect($body)->toContain('Bonjour.')->not->toContain('event: error')
        ->and(AiUsageLog::sole()->status)->toBe('success')
        ->and(AgentConversationMessage::where('role', 'assistant')->sole()->content)->toBe('Bonjour.');
});

it('persists failed turns and excludes them from replay', function (array $frames) {
    Http::fake(['*' => Http::response(providerStream($frames), 200, ['Content-Type' => 'text/event-stream'])]);
    $response = $this->postJson('/api/v1/assistant/chat', ['message' => 'Ma question', 'stream' => true, 'mode' => 'analysis']);
    $body = $response->streamedContent();
    $conversationId = $response->headers->get('X-Conversation-Id');
    expect($body)->toContain('event: error')->toContain('[DONE]')
        ->and(AiUsageLog::sole()->status)->toBe('error')
        ->and(AgentConversationMessage::count())->toBe(2)
        ->and(AgentConversationMessage::where('role', 'assistant')->sole()->meta['turn_status'])->toBe('error')
        ->and(app(ConversationStore::class)->getLatestConversationMessages($conversationId, 20))->toBeEmpty();
    $this->getJson('/api/v1/assistant/conversations/'.$conversationId)
        ->assertJsonCount(2, 'messages')
        ->assertJsonPath('messages.0.content', 'Ma question')
        ->assertJsonPath('messages.0.meta.mode', 'analysis')
        ->assertJsonPath('messages.1.meta.turn_status', 'error');
    expect(Cache::has(app(AssistantChatService::class)->cacheKey('Ma question', 'analysis', [])))->toBeFalse();
})->with([
    'provider error event' => [[['choices' => [['delta' => ['content' => 'Début de réponse.']]]], ['error' => ['message' => 'Provider unavailable', 'code' => 'unavailable']]]],
    'empty answer' => [[['choices' => [['delta' => ['content' => []], 'finish_reason' => 'stop']]]]],
    'token limit' => [[['choices' => [['delta' => ['content' => 'Texte incomplet'], 'finish_reason' => 'length']]]]],
    'missing finish reason' => [[['choices' => [['delta' => ['content' => 'Texte tronqué']]]]]],
]);

it('records the actual provider when a stream fails after starting', function () {
    Http::fake(['*' => Http::response(providerStream([
        ['model' => 'observed-model', 'choices' => [['delta' => ['content' => 'Début.']]]],
        ['error' => ['message' => 'Provider unavailable']],
    ]), 200, ['Content-Type' => 'text/event-stream'])]);
    $this->postJson('/api/v1/assistant/chat', ['message' => 'Question', 'stream' => true])->streamedContent();
    expect(AiUsageLog::sole()->provider)->toBe('mistral')
        ->and(AiUsageLog::sole()->model)->toBe('observed-model');
});

it('never caches an answer that depends on a previous conversation', function (bool $stream) {
    $user = auth()->user();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);
    MibekoIA::fake(['Réponse dépendante de mon dossier.']);
    $response = $this->postJson('/api/v1/assistant/chat/'.$conversation->id, ['message' => 'Et dans mon cas ?', 'stream' => $stream]);
    if ($stream) {
        $response->streamedContent();
    }
    expect(Cache::has(app(AssistantChatService::class)->cacheKey('Et dans mon cas ?', 'concise', [])))->toBeFalse();
})->with([true, false]);

it('preserves legally meaningful punctuation and accents in cache keys', function () {
    $service = app(AssistantChatService::class);
    expect($service->cacheKey('Article 1-2', 'concise', []))->not->toBe($service->cacheKey('Article 12', 'concise', []))
        ->and($service->cacheKey('côté', 'concise', []))->not->toBe($service->cacheKey('ct', 'concise', []));
});

it('completes several real streamed searches before a block-form answer', function () {
    Embeddings::fake();
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Texte test mariage']);
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '1']);
    ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => 'Le mariage nécessite le consentement.', 'validity_period' => '[2020-01-01,)']);
    $sequence = Http::sequence();
    foreach (['consentement', 'mariage'] as $i => $query) {
        $sequence->push(providerStream([
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_'.$i, 'function' => ['name' => 'SearchLegalDatabase', 'arguments' => json_encode(['query' => $query])]]]], 'finish_reason' => 'tool_calls']]],
        ]), 200, ['Content-Type' => 'text/event-stream']);
    }
    $sequence->push(providerStream([
        ['choices' => [['delta' => ['content' => [['type' => 'text', 'text' => 'Le consentement est requis [1].']]], 'finish_reason' => 'stop']]],
    ]), 200, ['Content-Type' => 'text/event-stream']);
    Http::fake(['*' => $sequence]);
    $body = $this->postJson('/api/v1/assistant/chat', ['message' => 'Le consentement au mariage', 'stream' => true])->streamedContent();
    expect($body)->toContain('event: sources')->toContain('consentement')->not->toContain('event: error')
        ->and(AiUsageLog::sole()->status)->toBe('success')
        ->and(AiUsageLog::sole()->tool_calls_count)->toBe(2);
    Http::assertSentCount(3);
});

it('can retry a failed conversation without replaying the failed turn', function () {
    Http::fake(['*' => Http::response(providerStream([['error' => ['message' => 'Unavailable']]]), 200, ['Content-Type' => 'text/event-stream'])]);
    $response = $this->postJson('/api/v1/assistant/chat', ['message' => 'Question à reprendre', 'stream' => true]);
    $response->streamedContent();
    $id = $response->headers->get('X-Conversation-Id');
    MibekoIA::fake(['Réponse complète.']);
    $body = $this->postJson('/api/v1/assistant/chat/'.$id, ['message' => 'Question à reprendre', 'stream' => true])->streamedContent();
    expect($body)->not->toContain('event: error')
        ->and(AgentConversationMessage::where('conversation_id', $id)->count())->toBe(4)
        ->and(app(ConversationStore::class)->getLatestConversationMessages($id, 20))->toHaveCount(2)
        ->and(AiUsageLog::where('status', 'success')->count())->toBe(1)
        ->and(AiUsageLog::where('status', 'error')->count())->toBe(1);
});
