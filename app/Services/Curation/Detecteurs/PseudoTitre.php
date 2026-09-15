<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\CurationFlag;
use App\Models\LegalDocument;

/**
 * Ajout v3 (`mibeko-dashboard#41`) : le titre officiel n'est qu'un fragment
 * de la formule de clôture administrative (« … et communiqué partout où
 * besoin sera. ») — jamais un vrai titre. Trouvé le 11/08/2026 sur 21
 * documents non publiés, chacun réduit à un seul article (le plus souvent
 * la signature seule) : la formule de fin a été retenue comme titre faute
 * d'objet identifiable ailleurs dans le texte.
 *
 * Liste FERMÉE de fragments déjà confirmés, pas une heuristique générale sur
 * « finit par une formule administrative » — le risque de faux positif sur
 * un titre légitime mais bref serait trop élevé sans davantage d'exemplaires
 * vérifiés (règle 1 du protocole : liste blanche, jamais liste noire).
 */
class PseudoTitre implements DetecteurContenu
{
    private const FRAGMENTS_CONNUS = [
        'communiqué partout où besoin sera',
    ];

    public function code(): string
    {
        return 'pseudo_titre';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    public function detecter(LegalDocument $document): array
    {
        $titre = mb_strtolower(trim(rtrim(trim($document->titre_officiel ?? ''), '. ')));

        if (! in_array($titre, self::FRAGMENTS_CONNUS, true)) {
            return [];
        }

        return [[
            'description' => "Le titre officiel « {$document->titre_officiel} » est un fragment de formule de clôture administrative, pas un titre.",
            'empreinte_source' => $titre,
            'anchor' => null,
        ]];
    }
}
