<?php

use App\Jobs\GenerateConversationTitle;
use App\Models\AgentConversation;
use Laravel\Ai\AnonymousAgent;

it('replaces the truncated title with an AI-generated one', function () {
    AnonymousAgent::fake(['Délais de préavis']);

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
    AnonymousAgent::fake(['']);

    $conversation = AgentConversation::factory()->create(['title' => 'Titre tronqué']);

    (new GenerateConversationTitle($conversation->id, 'Une question'))->handle();

    expect($conversation->fresh()->title)->toBe('Titre tronqué');
});
