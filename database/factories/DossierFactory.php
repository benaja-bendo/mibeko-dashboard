<?php

namespace Database\Factories;

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dossier>
 */
class DossierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $updatedAt = $this->faker->numberBetween(1_700_000_000_000, 1_800_000_000_000);

        return [
            'user_id' => User::factory(),
            'name' => $this->faker->sentence(3),
            'legal_domain' => $this->faker->randomElement(['Général', 'Travail', 'Famille', 'Pénal']),
            'tag' => $this->faker->randomElement(['EN_COURS', 'URGENT', 'ARCHIVE', 'FAVORIS']),
            'description' => $this->faker->optional()->paragraph(),
            'color' => $this->faker->hexColor(),
            'client_created_at' => $updatedAt - 86_400_000,
            'client_updated_at' => $updatedAt,
        ];
    }
}
