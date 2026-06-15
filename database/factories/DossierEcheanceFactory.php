<?php

namespace Database\Factories;

use App\Models\Dossier;
use App\Models\DossierEcheance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierEcheance>
 */
class DossierEcheanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $updatedAt = $this->faker->numberBetween(1_700_000_000_000, 1_800_000_000_000);

        return [
            'dossier_id' => Dossier::factory(),
            'type' => $this->faker->randomElement(DossierEcheance::TYPES),
            'title' => $this->faker->sentence(3),
            'due_date' => $this->faker->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'status' => 'a_venir',
            'is_confirmed' => false,
            'reminders' => [15, 7, 2, 0],
            'note' => $this->faker->optional()->sentence(),
            'client_created_at' => $updatedAt - 86_400_000,
            'client_updated_at' => $updatedAt,
        ];
    }

    /**
     * Échéance datée dans `$days` jours (utile pour tester les rappels).
     */
    public function dueInDays(int $days): static
    {
        return $this->state(fn (): array => [
            'due_date' => now()->addDays($days)->format('Y-m-d'),
            'status' => 'a_venir',
        ]);
    }
}
