<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\JurisprudenceCitation;
use App\Models\LegalDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JurisprudenceCitation>
 */
class JurisprudenceCitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'decision_id' => LegalDocument::factory(),
            'cited_article_id' => Article::factory(),
            'reference_brute' => "l'article ".$this->faker->numberBetween(1, 300)." de l'Acte uniforme portant sur le droit commercial général",
        ];
    }

    /**
     * Référence hors périmètre du corpus Mibeko (droit national d'un autre
     * État membre, Code civil...) : aucune correspondance à résoudre.
     */
    public function sansCorrespondance(): static
    {
        return $this->state(fn (): array => ['cited_article_id' => null]);
    }
}
