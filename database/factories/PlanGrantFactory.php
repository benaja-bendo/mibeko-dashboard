<?php

namespace Database\Factories;

use App\Models\PlanGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanGrant>
 */
class PlanGrantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan' => PlanGrant::PLAN_PRO,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'amount_fcfa' => 15_000,
            'channel' => 'mobile_money',
            'reference' => $this->faker->bothify('MM-########'),
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Octroi déjà expiré (utile pour tester le retour à `libre`).
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);
    }

    /**
     * Octroi qui n'a pas encore commencé.
     */
    public function future(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }
}
