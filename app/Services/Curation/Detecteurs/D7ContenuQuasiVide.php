<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D7 du registre `#23` : contenu quasi vide (moins de 20 caractères utiles).
 * Signature des documents « entièrement vides » repérés le 10/08/2026 (5
 * documents publiés, chacun réduit à un article ne contenant qu'un numéro
 * de page). Sévérité `blocking` (🔴 au registre, le marquage le plus élevé).
 *
 * Port fidèle de la v2 : `length(trim(coalesce(c,''))) < 20`.
 */
class D7ContenuQuasiVide extends DetecteurParArticle
{
    public function code(): string
    {
        return 'd7_contenu_quasi_vide';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        return mb_strlen(trim($contenu)) < 20;
    }

    protected function description(Article $article, string $contenu): string
    {
        return "L'article {$article->numero_article} a un contenu quasi vide (moins de 20 caractères) : texte probablement perdu.";
    }
}
