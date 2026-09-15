<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D5 du registre `#23` : le contenu se termine par un numéro de page façon
 * sommaire (« … Page. 1081 ») et reste court — signature d'une ligne de
 * sommaire ingérée comme si c'était un article. Le seuil de longueur seul
 * (« < 40 caractères ») avait été rejeté par le registre le 11/08 (il
 * aurait signalé l'article 433 du CPP, complet à 36 caractères) : la
 * condition du motif « Page N » est nécessaire, pas seulement la longueur.
 *
 * Port fidèle de la v2 : `c ~ 'Page[.:]? *[0-9]{1,4}\s*$' AND length(c) < 120`.
 */
class D5FragmentSommaire extends DetecteurParArticle
{
    private const MOTIF_PAGE_FIN = '/Page[.:]? *[0-9]{1,4}\s*$/';

    public function code(): string
    {
        return 'd5_fragment_sommaire';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        if (mb_strlen($contenu) >= 120) {
            return false;
        }

        return preg_match(self::MOTIF_PAGE_FIN, $contenu) === 1;
    }

    protected function description(Article $article, string $contenu): string
    {
        return "L'article {$article->numero_article} ressemble à une ligne de sommaire (se termine par un numéro de page), pas à un article réel.";
    }
}
