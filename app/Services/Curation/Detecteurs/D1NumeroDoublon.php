<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D1 du registre `#23` : le numéro d'article porte littéralement le mot
 * « doublon » — marqueur posé par un mécanisme de détection antérieur au
 * jeu v3 (résolution manuelle de collisions de numérotation), jamais un
 * vrai numéro d'article.
 *
 * Port fidèle de la v2 : `num LIKE '%doublon%'`.
 */
class D1NumeroDoublon extends DetecteurParArticle
{
    public function code(): string
    {
        return 'd1_numero_doublon';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        return str_contains($article->numero_article ?? '', 'doublon');
    }

    protected function description(Article $article, string $contenu): string
    {
        return "Le numéro d'article « {$article->numero_article} » porte un marqueur de doublon non résolu.";
    }
}
