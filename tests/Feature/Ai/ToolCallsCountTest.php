<?php

use App\Ai\Agents\MibekoIA;
use App\Models\AiUsageLog;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Sanctum\Sanctum;

/**
 * mibeko-dashboard#103 : le SDK n'expose pas le détail des jetons par étape
 * d'un agent en plusieurs appels — ce compte des recherches effectuées dans
 * le tour est le proxy retenu, corrélé au coût.
 */
beforeEach(function () {
    Embeddings::fake();

    $document = LegalDocument::factory()->create(['titre_officiel' => 'Code du comptage']);
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '1']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Disposition comptée.',
        'validity_period' => '[2020-01-01,)',
    ]);
});

it('compte deux recherches dans la réponse synchrone', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Queue::fake();

    MibekoIA::fake([
        new ToolCall(id: 'call_1', name: 'SearchLegalDatabase', arguments: ['query' => 'comptée']),
        new ToolCall(id: 'call_2', name: 'SearchLegalDatabase', arguments: ['query' => 'comptée']),
        'Réponse après deux recherches.',
    ]);

    $this->postJson('/api/v1/assistant/chat', ['message' => 'Une question qui cherche deux fois'])
        ->assertOk();

    $log = AiUsageLog::where('user_id', $user->id)->where('status', AiUsageLog::STATUS_SUCCESS)->sole();
    expect($log->tool_calls_count)->toBe(2);
});

it('compte une seule recherche dans la réponse en flux (SSE)', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Queue::fake();

    MibekoIA::fake([
        new ToolCall(id: 'call_1', name: 'SearchLegalDatabase', arguments: ['query' => 'comptée']),
        'Réponse après une recherche.',
    ]);

    // `streamedContent()` force la consommation du flux — sans quoi le
    // callback qui journalise l'usage (déclenché à la fin de l'itération)
    // ne s'exécute jamais dans le client de test.
    $this->postJson('/api/v1/assistant/chat', ['message' => 'Une question en flux', 'stream' => true])
        ->assertOk()
        ->streamedContent();

    $log = AiUsageLog::where('user_id', $user->id)->where('status', AiUsageLog::STATUS_SUCCESS)->sole();
    expect($log->tool_calls_count)->toBe(1);
});

it('laisse tool_calls_count à null pour une réponse servie depuis le cache', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Queue::fake();

    MibekoIA::fake(['Réponse sans recherche.']);

    $question = ['message' => 'Question mise en cache pour le comptage'];
    $this->postJson('/api/v1/assistant/chat', $question)->assertOk();
    $this->postJson('/api/v1/assistant/chat', $question)->assertOk()->assertJson(['cached' => true]);

    $cached = AiUsageLog::where('user_id', $user->id)->where('status', AiUsageLog::STATUS_CACHED)->sole();
    expect($cached->tool_calls_count)->toBeNull();
});
