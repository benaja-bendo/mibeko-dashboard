<?php

namespace Database\Factories;

use App\Models\Dossier;
use App\Models\DossierReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DossierReference>
 */
class DossierReferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dossier_id' => Dossier::factory(),
            'target_id' => $this->faker->uuid(),
            'type' => $this->faker->randomElement(DossierReference::TYPES),
            'title' => $this->faker->sentence(4),
            'breadcrumb' => $this->faker->optional()->words(3, true),
            'number' => $this->faker->optional()->numerify('Article ##'),
            'note' => $this->faker->optional()->sentence(),
        ];
    }
}
