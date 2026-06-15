<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
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
            'parent_node_id' => null,
            // Unique pour ne jamais violer la contrainte uq_articles_document_numero
            // (document_id, numero_article) quand plusieurs articles sont créés.
            'numero_article' => (string) $this->faker->unique()->numberBetween(1, 1_000_000),
            'ordre_affichage' => $this->faker->numberBetween(1, 1000),
            'validation_status' => $this->faker->randomElement(['pending', 'in_progress', 'validated']),
        ];
    }

    /**
     * Définir un document spécifique pour l'article.
     *
     * @return Factory<Article>
     */
    public function forDocument(int|string $documentId): Factory
    {
        return $this->state(function (array $attributes) use ($documentId) {
            return [
                'document_id' => $documentId,
            ];
        });
    }

    /**
     * Configurer la factory après la création.
     *
     * @return Factory<Article>
     */
    public function configure(): Factory
    {
        return $this->afterCreating(function (Article $article): void {
            if ($article->parent_node_id) {
                return;
            }

            $parentNode = StructureNode::factory()->create([
                'document_id' => $article->document_id,
            ]);

            $article->forceFill([
                'parent_node_id' => $parentNode->id,
            ])->save();
        });
    }
}
