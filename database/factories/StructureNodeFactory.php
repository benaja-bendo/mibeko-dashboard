<?php

namespace Database\Factories;

use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StructureNode>
 */
class StructureNodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => LegalDocument::factory(),
            'type_unite' => $this->faker->randomElement(['Livre', 'Titre', 'Chapitre']),
            'numero' => $this->faker->randomElement(['I', 'II', 'III', 'IV', 'V']),
            'titre' => $this->faker->sentence(),
            'tree_path' => $this->faker->slug(),
        ];
    }
}
