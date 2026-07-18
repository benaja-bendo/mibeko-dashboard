<?php

use App\Ai\Agents\ConversationTitler;
use App\Jobs\GenerateConversationTitle;
use App\Models\AgentConversation;

use function Pest\Laravel\assertDatabaseHas;

it('replaces the truncated title with an AI-generated one', function () {
    ConversationTitler::fake(['Délais de préavis']);

    $conversation = AgentConversation::factory()->create([
        'title' => 'Quel est le délai de préav...',
    ]);

    (new GenerateConversationTitle(
        $conversation->id,
        'Quel est le délai de préavis en cas de licenciement ?',
    ))->handle();

    expect($conversation->fresh()->title)->toBe('Délais de préavis');
});

it('keeps the existing title when the model returns nothing', function () {
    ConversationTitler::fake(['']);

    $conversation = AgentConversation::factory()->create(['title' => 'Titre tronqué']);

    (new GenerateConversationTitle($conversation->id, 'Une question'))->handle();

    expect($conversation->fresh()->title)->toBe('Titre tronqué');
});

it('laisse remonter l\'exception d\'un échec LLM pour que la file retente (retry honnête)', function () {
    // Le job idempotent ne doit plus avaler l'erreur : sinon `$tries`/`$backoff`
    // seraient fictifs. On vérifie que handle() propage bien l'exception.
    ConversationTitler::fake(fn () => throw new RuntimeException('LLM indisponible'));

    $conversation = AgentConversation::factory()->create(['title' => 'Titre tronqué']);

    expect(fn () => (new GenerateConversationTitle($conversation->id, 'Une question'))->handle())
        ->toThrow(RuntimeException::class);

    // Le titre par défaut est préservé (aucune écriture partielle sur échec).
    assertDatabaseHas('agent_conversations', [
        'id' => $conversation->id,
        'title' => 'Titre tronqué',
    ]);
});

it('conserve le titre par défaut quand toutes les tentatives échouent (hook failed)', function () {
    $conversation = AgentConversation::factory()->create(['title' => 'Titre tronqué']);

    // Le hook failed() ne fait que tracer : il ne touche pas au titre cosmétique.
    (new GenerateConversationTitle($conversation->id, 'Une question'))
        ->failed(new RuntimeException('échec définitif'));

    expect($conversation->fresh()->title)->toBe('Titre tronqué');
});
