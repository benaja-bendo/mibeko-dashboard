<?php

namespace Database\Factories;

use App\Ai\Agents\MibekoIA;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentConversationMessage>
 */
class AgentConversationMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => AgentConversation::factory(),
            'user_id' => User::factory(),
            'agent' => MibekoIA::class,
            'role' => 'user',
            'content' => fake()->sentence(),
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ];
    }

    /**
     * Réponse de l'assistant.
     */
    public function assistant(): static
    {
        return $this->state(fn (): array => [
            'role' => 'assistant',
            'content' => fake()->paragraph(),
        ]);
    }
}
