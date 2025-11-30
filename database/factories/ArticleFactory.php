<?php

namespace Database\Factories;

use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Article>
 */
class ArticleFactory extends Factory
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
            'parent_node_id' => StructureNode::factory(),
            'numero_article' => (string) $this->faker->numberBetween(1, 100),
            'ordre_affichage' => $this->faker->numberBetween(1, 1000),
        ];
    }
}
