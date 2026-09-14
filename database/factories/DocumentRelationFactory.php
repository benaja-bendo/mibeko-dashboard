<?php

namespace Database\Factories;

use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRelation>
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

    public function candidate(): static
    {
        return $this->state(fn () => [
            'status' => DocumentRelation::STATUS_CANDIDATE,
            'source' => DocumentRelation::SOURCE_HEURISTIC,
            'confidence' => $this->faker->randomFloat(4, 0.5, 0.95),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => DocumentRelation::STATUS_CONFIRMED,
            'source' => DocumentRelation::SOURCE_HUMAN,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => DocumentRelation::STATUS_REJECTED,
            'source' => DocumentRelation::SOURCE_HEURISTIC,
        ]);
    }
}
