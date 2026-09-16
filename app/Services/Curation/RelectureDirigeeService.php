<?php

namespace App\Services\Curation;

use App\Models\LegalDocument;
use Illuminate\Support\Collection;

/**
 * Calcule les deux exigences de l'étape 4 « Relecture dirigée » du protocole
 * de validation (`docs/pipeline/protocole-validation.md`) pour un document
 * (mibeko-dashboard#142, § 2.5 du plan « boîte de réception »).
 *
 * Le FRONT ne recalcule rien : il lit ce que ce service a produit via l'API
 * et confirme — un seul calcul déterministe, jamais deux implémentations
 * (PHP + TypeScript) susceptibles de diverger sur ce qui est exigé.
 */
class RelectureDirigeeService
{
    /** Taille du sondage — protocole, étape 5 : « quinze à trente articles ». */
    const TAILLE_SONDAGE = 15;

    /**
     * Premier article, dernier article, et les deux articles de part et
     * d'autre de chaque rupture de séquence (protocole, étape 4) — une
     * rupture est tout ordinal qui ne succède pas exactement au précédent
     * (trou, retour en arrière, doublon), parmi les articles dont le numéro
     * est numériquement interprétable (jamais PREAMBULE/SIGNATURE/TABLEAU_N,
     * hors sujet pour une séquence d'articles numérotés).
     *
     * @return array<int, string> Identifiants d'article, dans l'ordre de
     *                            découverte, jamais dupliqués.
     */
    public function pointsObligatoires(LegalDocument $document): Collection
    {
        $articles = $document->articles()->orderBy('ordre_affichage')->get(['id', 'numero_article']);
        if ($articles->isEmpty()) {
            return collect();
        }

        $points = collect([$articles->first()->id, $articles->last()->id]);

        $precedent = null;
        $precedentOrdinal = null;
        foreach ($articles as $article) {
            $ordinal = $this->ordinalDepuisNumero($article->numero_article);
            if ($ordinal !== null && $precedentOrdinal !== null && $ordinal !== $precedentOrdinal + 1) {
                $points->push($precedent->id);
                $points->push($article->id);
            }
            if ($ordinal !== null) {
                $precedent = $article;
                $precedentOrdinal = $ordinal;
            }
        }

        return $points->unique()->values();
    }

    /**
     * Échantillon déterministe de `$taille` articles — graine = identifiant
     * du document + version du jeu de détecteurs, jamais un tirage aléatoire
     * réel : deux ouvertures du même document contrôlé par la même version
     * du jeu doivent toujours produire le même sondage (protocole, étape 5 :
     * « re-vérifiable par quelqu'un d'autre plus tard »). Tri par empreinte
     * de (graine, article_id) plutôt qu'un générateur pseudo-aléatoire à
     * seed : reproductible à l'identique quel que soit le langage qui
     * recalculerait un jour la même chose, sans dépendre d'une implémentation
     * de PRNG particulière.
     *
     * @return array<int, string> Identifiants d'article.
     */
    public function sondage(LegalDocument $document, string $versionJeu, int $taille = self::TAILLE_SONDAGE): Collection
    {
        $graine = $document->id.'|'.$versionJeu;
        $articles = $document->articles()->pluck('id');

        if ($articles->count() <= $taille) {
            return $articles->values();
        }

        return $articles
            ->sortBy(fn (string $articleId) => hash('sha256', $graine.'|'.$articleId))
            ->take($taille)
            ->values();
    }

    private function ordinalDepuisNumero(?string $numero): ?int
    {
        if ($numero === null || preg_match('/^(\d+)/', trim($numero), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
