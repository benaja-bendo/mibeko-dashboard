<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;

/**
 * Détecteurs dont l'anomalie est une propriété du NUMÉRO d'article (D1, D2),
 * pas de son texte (API-021). L'empreinte d'une exception résolue porte donc
 * le numéro : changer le numéro invalide l'exception même si le texte est
 * resté le même, sans quoi un numéro encore fautif, mais différent de celui
 * que l'humain avait examiné, resterait « fidèle à la source ». Le contenu y
 * reste, comme pour tous les autres détecteurs : un texte réécrit se revoit.
 */
abstract class DetecteurParNumeroArticle extends DetecteurParArticle
{
    protected function empreinteSource(Article $article, string $contenu): string
    {
        return ($article->numero_article ?? '')."\n".$contenu;
    }

    /**
     * Jusqu'au 15/10/2026 l'empreinte de D1 et D2 était le contenu seul : les
     * exceptions déjà résolues portent celle-là et ne sont pas rouvertes en
     * bloc par le changement d'empreinte.
     *
     * @return array<int, string>
     */
    protected function empreintesHeritees(Article $article, string $contenu): array
    {
        return [$contenu];
    }
}
