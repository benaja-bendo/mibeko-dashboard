<?php

use App\Ai\Agents\MibekoIA;
use App\Ai\AiUsageLogger;
use App\Models\AiUsageLog;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * mibeko-dashboard#61 : une ligne par appel aux trois routes IA, succès comme
 * échec — « un 429 est une donnée ».
 */
function simulateAiThrottleHits(string $limitKey, int $hits, int $decaySeconds): void
{
    $key = md5('ai_assistant'.$limitKey);

    for ($i = 0; $i < $hits; $i++) {
        RateLimiter::hit($key, $decaySeconds);
    }
}

it('journalise un refus de quota (429) sans jamais atteindre le contrôleur', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    simulateAiThrottleHits('minute:'.$user->id, config('ai.quotas.standard.per_minute'), 60);

    $this->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertStatus(429);

    $log = AiUsageLog::where('user_id', $user->id)->sole();

    expect($log->route)->toBe('assistant/chat');
    expect($log->status)->toBe(AiUsageLog::STATUS_RATE_LIMITED);
    expect($log->tokens_input)->toBe(0);
    expect($log->cost_estimated_fcfa)->toBeNull();
});

it('journalise une réponse servie depuis le cache sans coût', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Le titre est produit par un job (ConversationTitler) : en test (file
    // `sync`) il s'exécuterait en ligne et appellerait un vrai fournisseur IA,
    // non couvert par MibekoIA::fake() — on neutralise la file pour rester
    // hermétique, aucune clé API en CI (cf. AiAssistantControllerTest.php).
    Queue::fake();

    MibekoIA::fake(['Réponse.']);

    $question = ['message' => 'Question identique pour le cache'];
    $this->postJson('/api/v1/assistant/chat', $question)->assertOk();
    $this->postJson('/api/v1/assistant/chat', $question)->assertOk()->assertJson(['cached' => true]);

    $logs = AiUsageLog::where('user_id', $user->id)->orderBy('created_at')->get();

    expect($logs)->toHaveCount(2);
    expect($logs->last()->status)->toBe(AiUsageLog::STATUS_CACHED);
    expect($logs->last()->cost_estimated_fcfa)->toBeNull();
});

it('journalise un appel synchrone réussi à l\'assistant', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MibekoIA::fake(['Réponse.']);

    $this->postJson('/api/v1/assistant/chat', ['message' => 'Une vraie question'])->assertOk();

    $log = AiUsageLog::where('user_id', $user->id)->sole();

    expect($log->route)->toBe('assistant/chat');
    expect($log->status)->toBe(AiUsageLog::STATUS_SUCCESS);
});

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
});

it('journalise un explain sans contenu quand l\'article n\'a pas de version active', function () {
    // `article_id` doit exister (règle de validation `exists:articles,id`) mais
    // sans version active `fetchArticleContext` rend null : c'est CE chemin
    // (pas un id inconnu, qui échoue à la validation avant le contrôleur) qui
    // exerce `streamError`.
    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi']);
    $doc = LegalDocument::factory()->create(['type_code' => 'LOI']);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1']);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/library/explain', ['article_id' => $article->id]);
    $response->assertOk();
    $response->streamedContent(); // exécute le callback du StreamedResponse.

    $log = AiUsageLog::where('user_id', $user->id)->sole();

    expect($log->route)->toBe('library/explain');
    expect($log->status)->toBe(AiUsageLog::STATUS_NO_CONTENT);
});

it('journalise un explain réussi avec fournisseur et modèle', function () {
    Embeddings::fake();
    AnonymousAgent::fake(['Explication.']);

    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi']);
    $doc = LegalDocument::factory()->create(['type_code' => 'LOI']);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '5']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Contenu de test.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/library/explain', ['article_id' => $article->id]);
    $response->assertOk();
    $response->streamedContent(); // exécute le callback du StreamedResponse.

    $log = AiUsageLog::where('user_id', $user->id)->sole();

    expect($log->route)->toBe('library/explain');
    expect($log->status)->toBe(AiUsageLog::STATUS_SUCCESS);
});

it('calcule le coût estimé depuis la grille tarifaire configurée', function () {
    config(['ai.pricing.mistral.mistral-large-latest' => [
        'input_per_million' => 1000,
        'output_per_million' => 2000,
    ]]);

    $user = User::factory()->create();
    $usage = new Usage(promptTokens: 5000, completionTokens: 600);

    $log = app(AiUsageLogger::class)->success($user, 'assistant/chat', 'mistral', 'mistral-large-latest', $usage);

    // (5000/1_000_000)*1000 + (600/1_000_000)*2000 = 5 + 1.2 = 6.2
    expect((float) $log->cost_estimated_fcfa)->toBe(6.2);
});

it('laisse le coût à null pour un couple fournisseur/modèle sans tarif connu', function () {
    $user = User::factory()->create();
    $usage = new Usage(promptTokens: 5000, completionTokens: 600);

    $log = app(AiUsageLogger::class)->success($user, 'assistant/chat', 'fournisseur-inconnu', 'modele-inconnu', $usage);

    expect($log->cost_estimated_fcfa)->toBeNull();
    expect($log->tokens_input)->toBe(5000);
});

it('ne journalise pas un 429 sur une route IA hors périmètre (outillage éditeur)', function () {
    // `legal-documents/{id}/analyze-ai` partage le limiteur `ai_assistant`
    // mais n'est pas l'une des trois routes suivies par #61 : avant le
    // correctif, AiRouteName::fromRequest() retournait le chemin complet
    // (non tronqué) pour toute route inconnue, ce qui débordait la colonne
    // `route` (varchar 40) dès le premier 429 sur ce endpoint.
    Permission::findOrCreate('documents.update');
    Role::findOrCreate('editor')->givePermissionTo('documents.update');
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    config(['ai.quotas.standard.per_minute' => 1]);
    $document = LegalDocument::factory()->create();

    $this->actingAs($editor)->postJson("/api/v1/legal-documents/{$document->id}/analyze-ai")->assertOk();
    $this->actingAs($editor)->postJson("/api/v1/legal-documents/{$document->id}/analyze-ai")->assertStatus(429);

    expect(AiUsageLog::count())->toBe(0);
});
