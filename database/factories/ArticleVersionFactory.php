<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArticleVersion>
 */
class ArticleVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'valid_from' => $this->faker->date(),
            'valid_until' => null,
            'contenu_texte' => $this->faker->paragraphs(3, true),
            'modifie_par_document_id' => null,
        ];
    }
}
