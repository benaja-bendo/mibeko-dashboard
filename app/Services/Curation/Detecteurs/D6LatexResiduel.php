<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D6 du registre `#23` : notation LaTeX résiduelle (`$...$`) dans le
 * contenu — 176 articles / 83 documents mesurés le 10/08/2026. Invisible
 * sur mibeko.fr (assaini au rendu par `sanitize.ts`), mais visible dans
 * l'espace pro et l'app mobile, et casse la recherche (`search_tsv` indexe
 * le brut, pas le rendu). Sévérité `warning` (🟠 au registre) : cosmétique
 * côté site public, réel ailleurs — jamais bloquant pour publication.
 *
 * Port fidèle de la v2 : `c ~ '\$[^$]+\$'`.
 */
class D6LatexResiduel extends DetecteurParArticle
{
    public function code(): string
    {
        return 'd6_latex_residuel';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_WARNING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        return preg_match('/\$[^$]+\$/', $contenu) === 1;
    }

    protected function description(Article $article, string $contenu): string
    {
        return "L'article {$article->numero_article} contient de la notation LaTeX résiduelle (\$...\$).";
    }
}
