<?php

use App\Ai\Agents\MibekoIA;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentMessageFeedback;
use App\Models\User;

/**
 * La politique de confidentialité (§ 2.2 et § 6) promet que l'export remet
 * les conversations avec l'assistant : titre, dates, questions et réponses.
 * Pas les `tool_results` : le texte intégral des articles retrouvés, qui ne
 * relève pas de l'usager et ferait gonfler l'export sans limite utile.
 */
function messageDeConversation(AgentConversation $conversation, string $role, string $content, string $createdAt, array $toolResults = []): AgentConversationMessage
{
    $message = AgentConversationMessage::create([
        'conversation_id' => $conversation->id,
        'user_id' => $conversation->user_id,
        'agent' => MibekoIA::class,
        'role' => $role,
        'content' => $content,
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => $toolResults,
        'usage' => [],
        'meta' => [],
    ]);
    // created_at n'est pas mass-assignable : fixé après coup pour un ordre stable.
    $message->created_at = $createdAt;
    $message->save();

    return $message;
}

it('exporte les conversations et leurs messages : rôle, contenu, date', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id, 'title' => 'Préavis de licenciement']);
    messageDeConversation($conversation, 'user', 'Quel préavis pour un CDI ?', '2026-09-20 10:00:00');
    // Tour « appel d'outil » : sans texte, il porte le texte des articles lus.
    messageDeConversation($conversation, 'assistant', '', '2026-09-20 10:00:05', [['name' => 'SearchLegalDatabase', 'result' => 'EXTRAIT-DU-CORPUS']]);
    messageDeConversation($conversation, 'assistant', 'Un mois, selon l\'article 39 [1].', '2026-09-20 10:00:10');

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $body = $response->streamedContent();
    $conversations = json_decode($body, true)['assistant_conversations'];

    expect($conversations)->toHaveCount(1)
        ->and($conversations[0]['title'])->toBe('Préavis de licenciement')
        ->and($conversations[0])->toHaveKeys(['created_at', 'updated_at'])
        ->and($conversations[0]['messages'])->toBe([
            ['role' => 'user', 'content' => 'Quel préavis pour un CDI ?', 'created_at' => '2026-09-20T10:00:00+00:00', 'feedback' => null],
            ['role' => 'assistant', 'content' => 'Un mois, selon l\'article 39 [1].', 'created_at' => '2026-09-20T10:00:10+00:00', 'feedback' => null],
        ])
        ->and($body)->not->toContain('EXTRAIT-DU-CORPUS');
});

it('retire des anciens messages le contexte RAG qui précède la question', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);
    messageDeConversation(
        $conversation,
        'user',
        "Voici les extraits de loi pertinents trouvés dans la base Mibeko :\n\nArticle 39 : EXTRAIT-DU-CORPUS\n\nQuestion de l'utilisateur : Quel préavis ?",
        '2026-09-20 10:00:00',
    );

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $body = $response->streamedContent();

    expect(json_decode($body, true)['assistant_conversations'][0]['messages'][0]['content'])->toBe('Quel préavis ?')
        ->and($body)->not->toContain('EXTRAIT-DU-CORPUS');
});

it('joint à une réponse l\'avis que l\'usager lui a donné', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);
    messageDeConversation($conversation, 'user', 'Quel préavis ?', '2026-09-20 10:00:00');
    $reponse = messageDeConversation($conversation, 'assistant', 'Un mois.', '2026-09-20 10:00:10');
    AgentMessageFeedback::create(['message_id' => $reponse->id, 'user_id' => $user->id, 'rating' => 'down', 'comment' => 'Article mal cité']);

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $messages = json_decode($response->streamedContent(), true)['assistant_conversations'][0]['messages'];

    expect($messages[0]['feedback'])->toBeNull()
        ->and($messages[1]['feedback'])->toBe(['rating' => 'down', 'comment' => 'Article mal cité']);
});

it('n\'exporte ni conversation ni avis d\'un autre compte', function () {
    $autrui = User::factory()->create();
    $conversationAutrui = AgentConversation::factory()->create(['user_id' => $autrui->id, 'title' => 'Conversation d\'autrui']);
    messageDeConversation($conversationAutrui, 'user', 'Question d\'autrui', '2026-09-20 09:00:00');
    $reponseAutrui = messageDeConversation($conversationAutrui, 'assistant', 'Réponse à autrui', '2026-09-20 09:00:10');
    AgentMessageFeedback::create(['message_id' => $reponseAutrui->id, 'user_id' => $autrui->id, 'rating' => 'down', 'comment' => 'Avis d\'autrui']);

    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id, 'title' => 'Ma conversation']);
    messageDeConversation($conversation, 'user', 'Ma question', '2026-09-20 10:00:00');

    $response = $this->actingAs($user)->get('/api/v1/profile/export');
    $body = $response->streamedContent();

    expect(array_column(json_decode($body, true)['assistant_conversations'], 'title'))->toBe(['Ma conversation'])
        ->and($body)->not->toContain('autrui');
});
