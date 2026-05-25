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
            'parent_node_id' => StructureNode::factory(),
            'numero_article' => (string) $this->faker->numberBetween(1, 100),
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
        return $this->afterMaking(function (Article $article) {
            // Si document_id est une factory, créer le document d'abord
            if ($article->document_id instanceof Factory) {
                $document = LegalDocument::factory()->create();
                $article->document_id = $document->id;
            }

            // Si parent_node_id est une factory, créer le nœud avec le même document
            if ($article->parent_node_id instanceof Factory) {
                $parentNode = StructureNode::factory()->create(['document_id' => $article->document_id]);
                $article->parent_node_id = $parentNode->id;
            }
        });
    }
}
