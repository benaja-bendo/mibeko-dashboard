<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\CurationFlag;
use App\Models\LegalDocument;

/**
 * D10 du registre `#23` : le titre officiel se termine par un mot-outil
 * (article, préposition, conjonction…) ou par un tiret/apostrophe orpheline
 * — signature d'un titre coupé avant sa fin. Défaut dominant mesuré sur la
 * population non publiée (`#41` : 188/505, 37 %) et objet d'une campagne de
 * reconstruction complète sur le publié (296 → ~28 documents, passe 7 du
 * registre). Contrairement aux autres détecteurs, celui-ci porte sur le
 * DOCUMENT (`titre_officiel`), jamais sur un article — `article_id` reste
 * `null` dans le candidat produit.
 *
 * Port fidèle de la v2 (le motif « mot-outil final » du CTE `d10` ; la
 * seconde moitié de ce CTE — PREAMBULE commençant en minuscule — est
 * couverte par `D3ArticleAmputeDebut`, voir sa docblock).
 */
class D10TitreTronque implements DetecteurContenu
{
    private const MOTS_OUTILS_FINAUX = '/ (la|le|les|des|du|de|d|l|et|à|au|aux|un|une|pour|sur|par|en|dans|leur|leurs|ce|cette|ces|portant|fixant|relatif|relative|approuvant|modifiant|complétant|créant|autorisant|accordant|déclarant)$/iu';

    private const TIRET_OU_APOSTROPHE_FINAL = "/[-­''’]$/u";

    public function code(): string
    {
        return 'd10_titre_tronque';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    public function detecter(LegalDocument $document): array
    {
        $titre = $document->titre_officiel ?? '';

        $tronque = preg_match(self::MOTS_OUTILS_FINAUX, $titre) === 1
            || preg_match(self::TIRET_OU_APOSTROPHE_FINAL, $titre) === 1;

        if (! $tronque) {
            return [];
        }

        return [[
            'description' => "Le titre officiel « {$titre} » se termine par un mot-outil ou un tiret/apostrophe orpheline : probablement tronqué.",
            'anchor' => null,
        ]];
    }
}
