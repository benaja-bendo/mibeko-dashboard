<?php

namespace Database\Factories;

use App\Models\LegalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DocumentRelation>
 */
class DocumentRelationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_doc_id' => LegalDocument::factory(),
            'target_doc_id' => LegalDocument::factory(),
            'relation_type' => $this->faker->randomElement(['MODIFIE', 'ABROGE', 'CITE', 'COMPLETE']),
            'commentaire' => $this->faker->sentence(),
        ];
    }
}
