<?php

use App\Ai\Storage\CompactingConversationStore;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\ToolResultMessage;

it('binds the compacting store as the conversation store', function () {
    // Le package résout ConversationStore via le conteneur (lecture ET écriture
    // de l'historique) : sans ce binding, la compaction ne s'appliquerait pas.
    expect(app(ConversationStore::class))->toBeInstanceOf(CompactingConversationStore::class);
});

/**
 * Construit un tour « assistant » avec un appel d'outil et son résultat.
 *
 * @param  string  $result  Contenu (texte intégral) renvoyé par l'outil.
 */
function toolTurn(string $conversationId, string $callId, string $result): void
{
    AgentConversationMessage::factory()->create([
        'conversation_id' => $conversationId,
        'role' => 'assistant',
        'content' => 'Réponse',
        'tool_calls' => [[
            'id' => $callId,
            'name' => 'SearchLegalDatabase',
            'arguments' => ['query' => 'x'],
            'result_id' => $callId,
        ]],
        'tool_results' => [[
            'id' => $callId,
            'name' => 'SearchLegalDatabase',
            'arguments' => ['query' => 'x'],
            'result' => $result,
            'result_id' => $callId,
        ]],
    ]);
}

it('keeps only the latest tool results in full and stubs older turns', function () {
    $conversation = AgentConversation::factory()->create();

    // Marqueurs ASCII : json_encode (comme en prod) échappe les accents en
    // \uXXXX, ce qui rendrait une assertion sur du texte accentué fragile.
    $oldFull = json_encode([['id' => 'a1', 'content' => 'MARQUEUR_ANCIEN '.str_repeat('x ', 50)]]);
    $newFull = json_encode([['id' => 'a2', 'content' => 'MARQUEUR_RECENT']]);

    AgentConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Première question',
    ]);
    toolTurn($conversation->id, 'tc1', $oldFull);

    AgentConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Seconde question',
    ]);
    toolTurn($conversation->id, 'tc2', $newFull);

    $messages = (new CompactingConversationStore)->getLatestConversationMessages($conversation->id, 20);

    $toolResults = collect($messages)
        ->filter(fn ($message) => $message instanceof ToolResultMessage)
        ->values();

    expect($toolResults)->toHaveCount(2);

    // Le tour ancien est réduit à un stub : le texte intégral disparaît.
    expect($toolResults[0]->toolResults->first()->result)
        ->not->toContain('MARQUEUR_ANCIEN')
        ->toContain('tokens');

    // Le tour le plus récent conserve son texte complet.
    expect($toolResults[1]->toolResults->first()->result)
        ->toContain('MARQUEUR_RECENT');
});

it('leaves the results of a single-turn conversation untouched', function () {
    $conversation = AgentConversation::factory()->create();
    $full = json_encode([['id' => 'a1', 'content' => 'MARQUEUR_UNIQUE']]);

    AgentConversationMessage::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Question unique',
    ]);
    toolTurn($conversation->id, 'tc1', $full);

    $messages = (new CompactingConversationStore)->getLatestConversationMessages($conversation->id, 20);

    $toolResults = collect($messages)
        ->filter(fn ($message) => $message instanceof ToolResultMessage)
        ->values();

    expect($toolResults)->toHaveCount(1);
    expect($toolResults[0]->toolResults->first()->result)->toContain('MARQUEUR_UNIQUE');
});
