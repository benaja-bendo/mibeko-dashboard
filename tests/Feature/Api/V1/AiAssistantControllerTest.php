<?php

use App\Ai\Agents\MibekoIA;
use App\Models\AgentConversation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('can list conversations for a user', function () {
    $user = User::factory()->create();
    AgentConversation::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/assistant/conversations');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('can retrieve a conversation details', function () {
    $user = User::factory()->create();
    $conversation = AgentConversation::factory()->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/assistant/conversations/{$conversation->id}");

    $response->assertStatus(200)
        ->assertJsonPath('id', $conversation->id);
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
