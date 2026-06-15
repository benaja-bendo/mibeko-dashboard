<?php

namespace App\Ai;

use App\Models\Tag;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Classifieur IA de « thèmes de vie » : propose 1 à 3 thèmes pour un texte
 * juridique, strictement dans la taxonomie existante. Assistance à la curation
 * (l'éditeur valide), jamais d'écriture automatique.
 */
class ThemeClassifier implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Tu es un classifieur de textes juridiques de la République du Congo (Congo-Brazzaville). '
            ."On te donne le titre et un extrait d'un texte, ainsi qu'une liste fermée de thèmes de vie. "
            .'Choisis les 1 à 3 thèmes les plus pertinents UNIQUEMENT dans cette liste. '
            .'Réponds STRICTEMENT par un objet JSON de la forme {"slugs": ["slug1", "slug2"]}, '
            ."sans aucun autre texte, sans balise de code. N'invente jamais de slug hors de la liste.";
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
            .'Réponds en JSON : {"slugs": [...]} (1 à 3 thèmes parmi la liste).';

        $response = $this->prompt($prompt);

        return $this->parseSlugs($response->text, $themes->pluck('slug')->all());
    }

    /**
     * Extrait et valide les slugs de la réponse du modèle (robuste au bavardage).
     *
     * @param  array<int, string>  $validSlugs
     * @return array<int, string>
     */
    private function parseSlugs(string $text, array $validSlugs): array
    {
        $slugs = [];

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            $slugs = is_array($decoded['slugs'] ?? null) ? $decoded['slugs'] : [];
        }

        return collect($slugs)
            ->filter(fn ($slug) => is_string($slug) && in_array($slug, $validSlugs, true))
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }
}
