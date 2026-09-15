<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D3 du registre `#23` : le contenu courant commence par une minuscule —
 * signature d'un début de texte perdu à l'extraction (une phrase française
 * commence par une majuscule, sauf la continuation légitime d'un ordinal
 * du type « 1er … »). S'applique aussi au PREAMBULE (numéro `PREAMBULE`) :
 * la v2 traitait ce cas séparément dans son CTE `d10` (exclusion de
 * conformité), mais le registre l'a depuis reclassé dans la famille D3
 * (commentaire du 11/08 : « 291 des 354 articles amputés sont des PREAMBULE
 * qui portent la suite d'un titre tronqué ») — un seul détecteur ici,
 * appliqué uniformément à tout article y compris le préambule, plutôt que
 * deux fragments SQL dupliqués pour la même anomalie.
 *
 * Port fidèle de la v2 : `c ~ '^[a-zà-ÿ]' AND left(c,3) <> 'er '`.
 */
class D3ArticleAmputeDebut extends DetecteurParArticle
{
    public function code(): string
    {
        return 'd3_article_ampute_debut';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        if ($contenu === '' || mb_substr($contenu, 0, 3) === 'er ') {
            return false;
        }

        return preg_match('/^[a-zà-ÿ]/u', $contenu) === 1;
    }

    protected function description(Article $article, string $contenu): string
    {
        return "L'article {$article->numero_article} commence par une minuscule : début de texte probablement perdu à l'extraction.";
    }
}
