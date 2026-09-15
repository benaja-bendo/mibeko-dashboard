<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D8 du registre `#23` : confusion OCR `I`/`1`/`0` au milieu d'un mot
 * (« iIlégal », « généra1e »). Sévérité `warning` (🟡 au registre :
 * cosmétique) — et sujet à de vraies exceptions « fidèle à la source »
 * (`mibeko-dashboard#27` : 5 des 9 cas mesurés le 10/08 sont des coquilles
 * du typographe du JO lui-même, sur une couche texte NATIVE, jamais
 * touchée par l'OCR — les corriger écarterait le corpus de sa source).
 * Le mécanisme d'exception par empreinte de contenu (§ 3.5) protège
 * précisément ce cas : c'est le détecteur d'origine du besoin.
 *
 * Port fidèle de la v2 : `c ~ '[a-zà-ÿ][I10][a-zà-ÿ]'`.
 */
class D8ConfusionOcr extends DetecteurParArticle
{
    public function code(): string
    {
        return 'd8_confusion_ocr';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_WARNING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        return preg_match('/[a-zà-ÿ][I10][a-zà-ÿ]/u', $contenu) === 1;
    }

    protected function description(Article $article, string $contenu): string
    {
        return "L'article {$article->numero_article} porte une confusion OCR probable (I/1/0 au milieu d'un mot).";
    }
}
