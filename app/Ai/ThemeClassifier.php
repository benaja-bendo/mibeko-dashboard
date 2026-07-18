<?php

namespace App\Ai;

use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Classifieur IA de « thèmes de vie » : propose 1 à 3 thèmes pour un texte
 * juridique, strictement dans la taxonomie existante. Assistance à la curation
 * (l'éditeur valide), jamais d'écriture automatique.
 *
 * Sortie structurée (schéma strict `{slugs: string[]}`, natif Mistral via
 * response_format json_schema) : le modèle ne peut plus « bavarder » ni emballer
 * sa réponse dans une balise de code. On conserve tout de même le filtrage à la
 * taxonomie (ceinture et bretelles). Tâche assistive non critique → tourne sur
 * le modèle le moins cher du fournisseur ({@see UseCheapestModel}).
 */
#[UseCheapestModel]
class ThemeClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Tu es un classifieur de textes juridiques de la République du Congo (Congo-Brazzaville). '
            ."On te donne le titre et un extrait d'un texte, ainsi qu'une liste fermée de thèmes de vie. "
            .'Choisis les 1 à 3 slugs les plus pertinents UNIQUEMENT dans cette liste. '
            ."N'invente jamais de slug hors de la liste ; si aucun thème ne convient vraiment, renvoie une liste vide.";
    }

    /**
     * Schéma de sortie : la liste des slugs choisis (bornée à 1-3 par le prompt).
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slugs' => $schema->array()
                ->items($schema->string())
                ->description('Slugs de thèmes choisis dans la liste fournie (1 à 3).'),
        ];
    }

    /**
     * Renvoie les slugs de thèmes proposés (1-3), filtrés à la taxonomie.
     *
     * @return array<int, string>
     */
    public function suggest(string $title, string $excerpt): array
    {
        $themes = Tag::orderBy('display_order')->get(['slug', 'name', 'description']);

        if ($themes->isEmpty()) {
            return [];
        }

        $catalog = $themes
            ->map(fn ($theme) => "- {$theme->slug} : {$theme->name} ({$theme->description})")
            ->implode("\n");

        $prompt = "Thèmes disponibles :\n{$catalog}\n\n"
            ."TITRE : {$title}\n\n"
            ."EXTRAIT :\n".mb_substr($excerpt, 0, 4000)."\n\n"
            .'Choisis 1 à 3 thèmes parmi la liste.';

        // La suggestion est une assistance optionnelle : une panne IA (réseau,
        // clé manquante, quota) ne doit jamais faire échouer l'endpoint.
        try {
            $response = $this->prompt($prompt);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $slugs = is_array($response['slugs'] ?? null) ? $response['slugs'] : [];
        $validSlugs = $themes->pluck('slug')->all();

        return collect($slugs)
            ->filter(fn ($slug) => is_string($slug) && in_array($slug, $validSlugs, true))
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }
}
