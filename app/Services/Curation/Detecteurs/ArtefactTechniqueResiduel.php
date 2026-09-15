<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\CurationFlag;

/**
 * Marqueur technique de l'usine à textes (`<!-- chunk`, `MIBEKO_PAGE`) resté
 * dans le contenu stocké — jamais un défaut de la SOURCE, toujours un bug du
 * pipeline (ces marqueurs doivent être retirés avant écriture en base).
 * Présent dans la requête v2 du registre `#23` mais jamais nommé D1-D10 dans
 * son historique : nom descriptif plutôt qu'un code inventé.
 *
 * Port fidèle de la v2 : `c LIKE '%<!-- chunk%' OR c LIKE '%MIBEKO_PAGE%'`.
 */
class ArtefactTechniqueResiduel extends DetecteurParArticle
{
    public function code(): string
    {
        return 'artefact_technique_residuel';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    protected function estAnomalie(string $contenu, Article $article): bool
    {
        return str_contains($contenu, '<!-- chunk') || str_contains($contenu, 'MIBEKO_PAGE');
    }

    protected function description(Article $article, string $contenu): string
    {
        return "L'article {$article->numero_article} contient un marqueur technique résiduel du pipeline d'ingestion.";
    }
}
