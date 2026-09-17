<?php

namespace App\Observers;

use App\Models\DocumentRelation;
use App\Services\LegalWatchSubscriptionNotifier;

/**
 * Alerte les abonnés du texte cible dès qu'une relation ABROGE/MODIFIE/COMPLETE
 * est confirmée (mibeko-dashboard#125). Contrairement à `LegalDocument`
 * (cf. `LegalWatchNotifier`), il n'existe aucun chemin `bulk`/query-builder
 * pour `DocumentRelation` : les deux points de confirmation
 * (`DocumentRelationController::store()`, création humaine déjà confirmée, et
 * `::valider()`, validation d'un candidat détecté) passent tous les deux par
 * Eloquent — un Observer les couvre donc sans duplication de déclencheur.
 */
class DocumentRelationObserver
{
    public function created(DocumentRelation $relation): void
    {
        $this->notifyIfConfirmed($relation);
    }

    public function updated(DocumentRelation $relation): void
    {
        if (! $relation->wasChanged('status')) {
            return;
        }

        $this->notifyIfConfirmed($relation);
    }

    private function notifyIfConfirmed(DocumentRelation $relation): void
    {
        if ($relation->status !== DocumentRelation::STATUS_CONFIRMED) {
            return;
        }

        app(LegalWatchSubscriptionNotifier::class)->relationConfirmed($relation);
    }
}
