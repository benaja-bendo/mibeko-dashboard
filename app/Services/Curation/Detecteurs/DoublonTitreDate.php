<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\CurationFlag;
use App\Models\LegalDocument;

/**
 * Ajout v3 (`mibeko-dashboard#41`) : un autre document VIVANT porte
 * exactement le même titre officiel (normalisé) et la même date — méthode
 * inspirée de `scripts/detect_document_duplicates.py` (passe 1, identité
 * ADMINISTRATIVE de l'acte), simplifiée ici au titre complet plutôt qu'au
 * couple type+numéro extrait : le script Python reste la référence pour un
 * dédoublonnage fin (confirmation par hash de contenu, passe 2) ; ce
 * détecteur ne fait QUE signaler un candidat pour revue humaine.
 *
 * Trouvé le 11/08/2026 : au moins 8 documents titrés identiquement
 * « DECISION DU 9 FEVRIER 1959 » à la même date, plus plusieurs paires
 * exactes (décret n° 2004-11, Décret n° 2025-379, Arrêté n° 3583).
 *
 * Compare à `date_signature`, à défaut `date_publication` — les deux jamais
 * mélangées entre les deux documents comparés (un doublon partage la MÊME
 * date, peu importe laquelle des deux colonnes la porte de chaque côté).
 */
class DoublonTitreDate implements DetecteurContenu
{
    public function code(): string
    {
        return 'doublon_titre_date';
    }

    public function severity(): string
    {
        return CurationFlag::SEVERITY_BLOCKING;
    }

    public function detecter(LegalDocument $document): array
    {
        $titre = trim($document->titre_officiel ?? '');
        $date = $document->date_signature ?? $document->date_publication;

        if ($titre === '' || $date === null) {
            return [];
        }

        $jumeauExiste = LegalDocument::query()
            ->whereKeyNot($document->id)
            ->whereRaw('lower(trim(titre_officiel)) = ?', [mb_strtolower($titre)])
            ->where(function ($requete) use ($date) {
                $requete->whereDate('date_signature', $date)
                    ->orWhereDate('date_publication', $date);
            })
            ->exists();

        if (! $jumeauExiste) {
            return [];
        }

        return [[
            'description' => 'Un autre document vivant porte exactement le même titre officiel et la même date : doublon probable.',
            'anchor' => null,
        ]];
    }
}
