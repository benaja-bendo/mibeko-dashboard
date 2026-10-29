<?php

use App\Ai\Agents\MibekoIA;
use App\Ai\AssistantChatService;
use App\Ai\Mistral\FinalAnswerMistralGateway;
use App\Ai\Mistral\FinalAnswerMistralProvider;
use App\Ai\Tools\SearchLegalDatabase;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AiUsageLog;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Gateway\StepContext;
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
        // mibeko-dashboard#137 : aucune recherche appelée, donc aucune source
        // possible — has_citation doit rester false, jamais null (le statut
        // est bien success) ni true (rien n'a été cité).
        ->and(AiUsageLog::sole()->has_citation)->toBeFalse()
        ->and(AgentConversationMessage::where('role', 'assistant')->sole()->content)->toBe('Bonjour.');
});

it('marks a no_result success (searched, nothing found) as uncited, distinct from an error', function () {
    // mibeko-dashboard#137 : le corpus est interrogé mais vide — un succès
    // applicatif distinct d'un échec (meta.no_result), avec has_citation
    // false (aucune source), pas null (le statut reste success).
    Embeddings::fake();
    $sequence = Http::sequence()
        ->push(providerStream([
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_0', 'function' => ['name' => 'SearchLegalDatabase', 'arguments' => json_encode(['query' => 'terme introuvable'])]]]], 'finish_reason' => 'tool_calls']]],
        ]), 200, ['Content-Type' => 'text/event-stream'])
        ->push(providerStream([
            ['choices' => [['delta' => ['content' => [['type' => 'text', 'text' => "Je n'ai rien trouvé dans le corpus."]]], 'finish_reason' => 'stop']]],
        ]), 200, ['Content-Type' => 'text/event-stream']);
    Http::fake(['*' => $sequence]);

    $body = $this->postJson('/api/v1/assistant/chat', ['message' => 'Une question sans réponse', 'stream' => true])->streamedContent();

    expect($body)->not->toContain('event: error')
        ->and(AiUsageLog::sole()->status)->toBe('success')
        ->and(AiUsageLog::sole()->has_citation)->toBeFalse()
        ->and(AgentConversationMessage::where('role', 'assistant')->sole()->meta['no_result'] ?? null)->toBeTrue();
});

it('persists failed turns and excludes them from replay', function (array $frames) {
    Http::fake(['*' => Http::response(providerStream($frames), 200, ['Content-Type' => 'text/event-stream'])]);
    $response = $this->postJson('/api/v1/assistant/chat', ['message' => 'Ma question', 'stream' => true, 'mode' => 'analysis']);
    $body = $response->streamedContent();
    $conversationId = $response->headers->get('X-Conversation-Id');
    expect($body)->toContain('event: error')->toContain('[DONE]')
        ->and(AiUsageLog::sole()->status)->toBe('error')
        // mibeko-dashboard#137 : non applicable sur un échec — jamais false
        // (qui signifierait "réussi mais sans source").
        ->and(AiUsageLog::sole()->has_citation)->toBeNull()
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
        // mibeko-dashboard#137 : des sources ont bien été trouvées et citées.
        ->and(AiUsageLog::sole()->has_citation)->toBeTrue()
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

it('forces a written answer on the last step instead of ending on another search', function (bool $stream) {
    // mibeko-dashboard#257 : une question large faisait chercher le modèle à chaque
    // étape. À la dernière il réclamait encore l'outil, le SDK n'en exécute aucun à ce
    // stade et s'arrête sans texte (« Réponse IA incomplète (fin : tool_calls) »).
    // Mistral doit donc recevoir tool_choice=none à cette étape, et à elle seule.
    Embeddings::fake();
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Texte test société']);
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '1']);
    ArticleVersion::factory()->create(['article_id' => $article->id, 'contenu_texte' => 'La société est constituée par contrat.', 'validity_period' => '[2020-01-01,)']);
    $headers = $stream ? ['Content-Type' => 'text/event-stream'] : [];
    $recherche = fn (int $i) => ['id' => 'call_'.$i, 'function' => ['name' => 'SearchLegalDatabase', 'arguments' => json_encode(['query' => 'société '.$i])]];
    $sequence = Http::sequence();
    foreach (range(0, 4) as $i) {
        $sequence->push($stream
            ? providerStream([['choices' => [['delta' => ['tool_calls' => [['index' => 0, ...$recherche($i)]]], 'finish_reason' => 'tool_calls']]]])
            : ['choices' => [['message' => ['content' => '', 'tool_calls' => [$recherche($i)]], 'finish_reason' => 'tool_calls']]], 200, $headers);
    }
    $sequence->push($stream
        ? providerStream([['choices' => [['delta' => ['content' => 'La société est constituée par contrat [1].'], 'finish_reason' => 'stop']]]])
        : ['choices' => [['message' => ['content' => 'La société est constituée par contrat [1].'], 'finish_reason' => 'stop']]], 200, $headers);
    Http::fake(['*' => $sequence]);

    $response = $this->postJson('/api/v1/assistant/chat', ['message' => 'Comment constituer une société ?', 'stream' => $stream]);
    $reponse = $stream ? $response->streamedContent() : $response->json('reply');
    $choix = Http::recorded()->map(fn (array $echange) => $echange[0]->data()['tool_choice'] ?? null)->all();
    $rappels = Http::recorded()->map(fn (array $echange) => str_contains($echange[0]->data()['messages'][0]['content'] ?? '', FinalAnswerMistralGateway::RAPPEL_DERNIERE_ETAPE))->all();

    expect($choix)->toBe(['required', 'auto', 'auto', 'auto', 'auto', 'none'])
        ->and($rappels)->toBe([false, false, false, false, false, true])
        ->and($reponse)->toContain('constituée par contrat')->not->toContain('event: error')
        ->and(AiUsageLog::sole()->status)->toBe('success');
})->with([true, false]);

it('only forbids tools on the last step of a multi-step run', function (int $etape, bool $derniere, bool $avecOutils, ?string $attendu) {
    $gateway = new FinalAnswerMistralGateway(app(Dispatcher::class));
    $provider = new FinalAnswerMistralProvider(['driver' => 'mistral', 'name' => 'mistral', 'key' => 'test-only'], app(Dispatcher::class));

    $corps = (fn () => $this->buildStepBody($provider, 'mistral-large-latest', 'Consignes de test.', [], $avecOutils ? [new SearchLegalDatabase] : [], null, null, new StepContext(stepNumber: $etape, isFinalStep: $derniere)))->call($gateway);
    $consignes = $corps['messages'][0]['content'];

    // Le rappel accompagne l'interdiction, jamais seul, et les consignes de l'agent restent.
    expect($corps['tool_choice'] ?? null)->toBe($attendu)
        ->and($consignes)->toStartWith('Consignes de test.')
        ->and(str_contains($consignes, FinalAnswerMistralGateway::RAPPEL_DERNIERE_ETAPE))->toBe($attendu === 'none');
})->with([
    'first of several steps' => [0, false, true, 'auto'],
    'middle step' => [2, false, true, 'auto'],
    'last step' => [5, true, true, 'none'],
    'only step of a single-step run' => [0, true, true, 'auto'],
    'last step of an agent without tools' => [5, true, false, null],
]);

it('treats a tool call written as text as an incomplete answer, never as a cached success', function () {
    // mibeko-dashboard#257 : forcé d'écrire à la dernière étape, le modèle a parfois rendu
    // son appel d'outil en JSON. Ce n'est pas une réponse : erreur, et rien en cache.
    Embeddings::fake();
    $sequence = Http::sequence()
        ->push(providerStream([
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_0', 'function' => ['name' => 'SearchLegalDatabase', 'arguments' => json_encode(['query' => 'société'])]]]], 'finish_reason' => 'tool_calls']]],
        ]), 200, ['Content-Type' => 'text/event-stream'])
        ->push(providerStream([
            ['choices' => [['delta' => ['content' => '{"query": "constitution société", "limit": 5}'], 'finish_reason' => 'stop']]],
        ]), 200, ['Content-Type' => 'text/event-stream']);
    Http::fake(['*' => $sequence]);

    $body = $this->postJson('/api/v1/assistant/chat', ['message' => 'Comment constituer une société ?', 'stream' => true])->streamedContent();

    expect($body)->toContain('event: error')
        ->and(AiUsageLog::sole()->status)->toBe('error')
        ->and(AiUsageLog::sole()->error_message)->toContain('appel d\'outil écrit en texte')
        ->and(Cache::has(app(AssistantChatService::class)->cacheKey('Comment constituer une société ?', 'concise', [])))->toBeFalse();
});

it('recognises a bare JSON object as a tool call and nothing else', function (string $texte, bool $attendu) {
    expect(app(AssistantChatService::class)->isToolCallText($texte))->toBe($attendu);
})->with([
    'tool call object' => ['{"query": "constitution société", "limit": 5}', true],
    'padded object' => ["  {\"query\":\"x\"}\n", true],
    'array of objects' => ['[{"query":"x"}]', true],
    'sourced sentence' => ["Le préavis est d'un mois [1].", false],
    'marker first' => ['[1] Le préavis est d\'un mois.', false],
    'braces inside a sentence' => ['La clause {préavis} est valable.', false],
    'empty text' => ['', false],
]);
