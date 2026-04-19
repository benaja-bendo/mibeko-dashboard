<?php

use App\Ai\Agents\MibekoIA;
use App\Models\AgentConversation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use Illuminate\Support\Facades\Cache;

it('can list conversations for a user', function () {
    $user = User::factory()->create();
    AgentConversation::factory()->count(3)->create(['user_id' => $user->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/assistant/conversations');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('caches the ai response for identical queries', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    MibekoIA::fake([
        'Ceci est une réponse de test qui sera mise en cache.',
    ]);

    // Première requête : doit appeler l'IA et mettre en cache
    $response1 = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quelles sont les conditions de mariage ?',
    ]);

    $response1->assertStatus(200)
        ->assertJson([
            'reply' => 'Ceci est une réponse de test qui sera mise en cache.',
        ]);

    MibekoIA::assertPrompted('Quelles sont les conditions de mariage ?');

    // Vérifier que la réponse est dans le cache
    $normalizedMessage = 'quelles sont les conditions de mariage'; // sans point d'interrogation et espaces en trop
    $cacheKey = 'ai_response_' . md5($normalizedMessage);
    expect(Cache::has($cacheKey))->toBeTrue();

    // Deuxième requête identique : doit utiliser le cache
    $response2 = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'Quelles sont les conditions de mariage ?',
    ]);

    $response2->assertStatus(200)
        ->assertJson([
            'reply' => 'Ceci est une réponse de test qui sera mise en cache.',
            'cached' => true
        ]);
    
    // Une nouvelle conversation a été créée même si le cache est utilisé
    expect(AgentConversation::where('user_id', $user->id)->count())->toBe(2);
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
