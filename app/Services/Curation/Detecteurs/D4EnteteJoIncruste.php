<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D4 du registre `#23` : en-tête de Journal officiel incrusté dans le
 * contenu d'un article (numéro d'édition suivi de « Journal officiel », ou
 * la formule complète suivie d'un numéro/date) — le défaut le plus répandu
 * mesuré au 10/08/2026 (500+ articles), et le plus mécaniquement réparable.
 *
 * Port fidèle de la v2 (deux motifs OR, insensibles à la casse) :
 * `c ~ '(?i)\d{3,4}\njournal officiel'`
 * `c ~ '(?i)journal officiel de la r[eé]publique du congo\n(n° |\d{3,4}|du )'`
 */
class D4EnteteJoIncruste extends DetecteurParArticle
{
    private const MOTIF_NUMERO_EDITION = '/\d{3,4}\njournal officiel/iu';

    private const MOTIF_FORMULE_COMPLETE = '/journal officiel de la r[eé]publique du congo\n(n° |\d{3,4}|du )/iu';

    public function code(): string
    {
        return 'd4_entete_jo_incruste';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        if ($contenu === '') {
            return false;
        }

        return preg_match(self::MOTIF_NUMERO_EDITION, $contenu) === 1
            || preg_match(self::MOTIF_FORMULE_COMPLETE, $contenu) === 1;
    }

    protected function description(Article $article, string $contenu): string
    {
        return "L'article {$article->numero_article} contient un en-tête de Journal officiel incrusté dans son texte.";
    }
}
