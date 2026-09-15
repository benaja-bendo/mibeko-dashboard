<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * D2 du registre `#23` : numéro d'article hors liste blanche. Règle n° 1 du
 * protocole (`docs/pipeline/protocole-validation.md`) — un contrôle en liste
 * NOIRE (chercher les numéros contenant une lettre) n'en trouvait que 51 ;
 * la liste blanche en trouve plus de 300, dont le cas fondateur
 * `au-commercial-general`, article numéroté « 3- L ».
 *
 * Port fidèle de la v2 (liste blanche volontairement identique à celle du
 * corps de `#23`, PAS la version élargie du protocole qui ajoute
 * `DISPOSITION_N`/`NOTE_N` — les ajouter ici romprait le critère de
 * non-régression de #141 : reproduire exactement les compteurs v2 avant
 * toute activation des ajouts v3).
 */
class D2NumeroHorsListeBlanche extends DetecteurParArticle
{
    private const LISTE_BLANCHE = '/^(premier|unique|1er|PREAMBULE|SIGNATURE|ANNEXE|TABLEAU_[0-9]+|[0-9]+(-[0-9]+)?( ?(bis|ter|quater|quinquies))?)$/i';

    public function code(): string
    {
        return 'd2_numero_hors_liste_blanche';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        return preg_match(self::LISTE_BLANCHE, $article->numero_article ?? '') !== 1;
    }

    protected function description(Article $article, string $contenu): string
    {
        return "Le numéro d'article « {$article->numero_article} » n'est pas dans la liste blanche des formes autorisées.";
    }
}
