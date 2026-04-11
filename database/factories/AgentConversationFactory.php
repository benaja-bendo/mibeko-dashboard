<?php

namespace Database\Factories;

use App\Models\AgentConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentConversation>
 */
class AgentConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->uuid(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
