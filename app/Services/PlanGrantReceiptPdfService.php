<?php

namespace App\Services;

use App\Models\PlanGrant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

/**
 * Rendu du justificatif (reçu de confirmation) d'un octroi Pro vendu à la
 * main — mibeko-dashboard#121.
 *
 * Volontairement PAS un rendu mis en cache sur disque comme
 * {@see DocumentExportPdfService} : celui-ci existe parce qu'un document
 * juridique complet coûte cher à rendre (des milliers d'articles) ; un reçu
 * ne porte qu'une poignée de champs déjà en base, le re-rendre à chaque
 * demande reste trivial et évite de gérer une invalidation de cache pour un
 * volume d'octrois encore faible (~15 comptes Pro au 09/09/2026).
 *
 * Volontairement PAS une facture : Mibeko n'a pas d'entité juridique (pas de
 * NIU/RCCM — docs/decisions.md du 01/08/2026), donc aucune facture opposable
 * ne peut être émise. Le gabarit dit explicitement « reçu de confirmation »
 * et porte cette réserve en toutes lettres, pour ne jamais laisser croire à
 * une pièce comptable conforme.
 */
class PlanGrantReceiptPdfService
{
    public function filenameFor(PlanGrant $grant): string
    {
        return 'mibeko-recu-'.Str::lower($grant->id).'.pdf';
    }

    public function render(PlanGrant $grant): string
    {
        $grant->loadMissing(['user:id,name,email', 'user.settings', 'creator:id,name']);

        if (ob_get_length()) {
            ob_end_clean();
        }

        $pdf = Pdf::loadView('billing.plan_grant_receipt', ['grant' => $grant]);
        $pdf->setPaper('a4');
        $pdf->setOption('isHtml5ParserEnabled', true);
        // Aucune ressource distante référencée dans le gabarit : même
        // durcissement anti-SSRF que les autres exports PDF de l'app.
        $pdf->setOption('isRemoteEnabled', false);

        return $pdf->output();
    }
}
