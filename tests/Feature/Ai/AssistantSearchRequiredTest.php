<?php

use App\Ai\Agents\MibekoIA;
use App\Ai\AssistantChatService;
use App\Models\AgentConversationMessage;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ToolChoice;
use Laravel\Sanctum\Sanctum;

/**
 * mibeko-dashboard#228 : une question ne reçoit jamais un avis rendu sans
 * recherche dans le fonds. Mesuré le 01/10/2026 : 15 réponses sur 93 en
 * septembre n'avaient rien cherché, dont un avis faux sur le divorce.
 */
beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    // Le titre de conversation part sur la file : neutralisée pour ne jamais
    // appeler un vrai fournisseur IA.
    Queue::fake();
});

it('ne dispense de recherche que les formules de politesse', function (string $message, bool $attendu) {
    expect(app(AssistantChatService::class)->isSmallTalk($message))->toBe($attendu);
})->with([
    'salutation' => ['Bonjour', true],
    'remerciement ponctué' => ['Merci beaucoup !', true],
    'formules enchaînées' => ["D'accord, merci", true],
    'avec le nom' => ['Salut Mibeko', true],
    'question sur l\'assistant' => ['Qui es-tu ?', true],
    'deux mots-clés' => ['licenciement abusif', false],
    'salutation suivie d\'une question' => ["Bonjour, mon employeur m'a licencié sans préavis", false],
    'un seul mot juridique' => ['Divorce ?', false],
    'relance de la conversation' => ['oui', false],
    'question de suite' => ['Et pour un CDD ?', false],
]);

it('oblige le modèle à chercher à la première étape, puis le laisse répondre', function () {
    $options = TextGenerationOptions::forAgent(new MibekoIA);

    expect($options->toolChoice?->mode)->toBe(ToolChoice::required)
        ->and($options->forStep(1)->toolChoice)->toBeNull()
        ->and(TextGenerationOptions::forAgent(new MibekoIA(searchRequired: false))->toolChoice)->toBeNull();
});

it('remplace une réponse rendue sans recherche, sans la mettre en cache', function () {
    MibekoIA::fake(['Le préavis est de trois jours.', 'Le préavis est de trois jours.']);
    $question = ['message' => 'Quel préavis pour un licenciement ?'];

    $this->postJson('/api/v1/assistant/chat', $question)
        ->assertOk()
        ->assertJsonPath('reply', AssistantChatService::UNGROUNDED_REPLY);

    $persiste = AgentConversationMessage::where('role', 'assistant')->latest('id')->first();
    expect($persiste->content)->toBe(AssistantChatService::UNGROUNDED_REPLY)
        ->and($persiste->meta['search_skipped'] ?? false)->toBeTrue();

    // Même question : le modèle est rappelé, rien n'a été mis en cache.
    $this->postJson('/api/v1/assistant/chat', $question)
        ->assertOk()
        ->assertJsonMissingPath('cached');
});

it('ne laisse rien filer dans le flux quand le modèle répond sans chercher', function () {
    MibekoIA::fake(['Le préavis est de trois jours.']);

    $flux = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quel préavis pour un licenciement ?',
        'stream' => true,
    ])->streamedContent();

    expect($flux)->toContain("Je n'ai pas pu appuyer cette réponse")
        ->and($flux)->not->toContain('trois jours')
        ->and($flux)->not->toContain('event: error');

    expect(AgentConversationMessage::where('role', 'assistant')->latest('id')->first()->content)
        ->toBe(AssistantChatService::UNGROUNDED_REPLY);
});

it('laisse passer une formule de politesse sans recherche', function () {
    MibekoIA::fake(['Bonjour, en quoi puis-je vous aider ?']);

    $this->postJson('/api/v1/assistant/chat', ['message' => 'Bonjour'])
        ->assertOk()
        ->assertJsonPath('reply', 'Bonjour, en quoi puis-je vous aider ?');
});
