<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D9 du registre `#23` : balises HTML brutes dans le contenu (`<sup>`,
 * `<table>`…) — markup qui a fui du parseur au lieu d'être linéarisé.
 * Sévérité `warning` : markup visible mais texte dessous resté lisible,
 * contrairement à une perte de contenu.
 *
 * Port fidèle de la v2 : `c ~ '</?(sup|sub|table|tr|td|th|br)( [^>]*)?>'`.
 */
class D9BalisageHtmlBrut extends DetecteurParArticle
{
    private const MOTIF_BALISE = '#</?(sup|sub|table|tr|td|th|br)( [^>]*)?>#';

    public function code(): string
    {
        return 'd9_balisage_html_brut';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_WARNING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        return preg_match(self::MOTIF_BALISE, $contenu) === 1;
    }

    protected function description(Article $article, string $contenu): string
    {
        return "L'article {$article->numero_article} contient du balisage HTML brut non linéarisé.";
    }
}
