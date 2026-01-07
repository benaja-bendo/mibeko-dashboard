<?php

namespace Database\Factories;

use App\Models\Article;
use Illuminate\Database\Eloquent\Factories\Factory;

use App\Models\ArticleVersion;

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
            'validity_period' => ArticleVersion::makeValidityPeriod($this->faker->date()),
            'contenu_texte' => $this->faker->paragraphs(3, true),
            'validation_status' => 'validated',
            'modifie_par_document_id' => null,
        ];
    }
}
