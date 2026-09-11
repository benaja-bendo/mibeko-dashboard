<?php

namespace App\Services\Curation;

use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\PublicationChecklist;
use App\Models\User;

/**
 * Garde-fou de publication, seul et unique (dashboard#119) — remplace les
 * vérifications dupliquées entre `LegalDocumentController::update()` (chemin
 * unitaire) et `bulkUpdate()` (chemin en masse). Étend l'existant (article,
 * date d'entrée en vigueur, flags bloquants) d'un critère de provenance,
 * sans changer les règles déjà en place :
 *  - l'unitaire ne bloque que sur les flags `severity=blocking` (ou NULL,
 *    compat historique) et peut passer outre avec `force=true` ;
 *  - le lot bloque sur TOUTE anomalie non résolue, plus strict, jamais de
 *    `force` — durcissement volontaire déjà en place, préservé via
 *    `$strictFlags`.
 *
 * `evaluate()` est pure (aucune écriture) : le contrôleur décide quand
 * appeler `record()`, seulement une fois la transition réellement actée —
 * sinon un document dont la machine à états refuse la transition (ex.
 * draft → published) laisserait une preuve « passed » mensongère alors
 * qu'aucune publication n'a eu lieu.
 */
class PublicationGuardrail
{
    /**
     * @param  array{date_entree_vigueur?: mixed, date_entree_vigueur_inconnue?: bool, provenance_inconnue?: bool, metadata?: ?array}  $pending
     *                                                                                                                                           Valeurs en attente d'écriture (payload de la requête), pas encore persistées :
     *                                                                                                                                           permet d'évaluer AVANT `$document->update()`, sur l'état que le document AURA.
     */
    public function evaluate(
        LegalDocument $document,
        array $pending = [],
        bool $force = false,
        bool $strictFlags = false,
    ): PublicationGuardrailResult {
        $hasArticle = $document->articles()->exists();

        $dateEntreeVigueur = array_key_exists('date_entree_vigueur', $pending)
            ? $pending['date_entree_vigueur']
            : $document->date_entree_vigueur;
        $dateInconnueAssumee = (bool) ($pending['date_entree_vigueur_inconnue'] ?? $document->date_entree_vigueur_inconnue);
        $dateOk = ! is_null($dateEntreeVigueur) || $dateInconnueAssumee;

        $metadata = array_key_exists('metadata', $pending) ? $pending['metadata'] : $document->metadata;
        $provenanceConnue = ! empty($metadata['source_url'] ?? null) && ! empty($metadata['fetched_at'] ?? null);
        $provenanceInconnueAssumee = (bool) ($pending['provenance_inconnue'] ?? $document->provenance_inconnue);
        $provenanceOk = $provenanceConnue || $provenanceInconnueAssumee;

        $flagsQuery = $document->curationFlags()->where('resolved', false);
        if (! $strictFlags) {
            $flagsQuery->where(fn ($q) => $q
                ->where('severity', CurationFlag::SEVERITY_BLOCKING)
                ->orWhereNull('severity'));
        }
        $blockingFlagsCount = $flagsQuery->count();
        $flagsOk = $blockingFlagsCount === 0;

        $reasons = [];

        if (! $hasArticle) {
            $reasons['articles'] = 'Un document sans article ne peut pas être publié.';
        }

        if (! $dateOk) {
            $reasons['date_entree_vigueur'] = "La date d'entrée en vigueur est inconnue. Renseignez-la, ou confirmez explicitement "
                ."(date_entree_vigueur_inconnue) qu'elle est inconnue pour publier quand même.";
        }

        if (! $provenanceOk) {
            $reasons['metadata'] = 'La provenance de ce document (source, date de récupération) est inconnue. Renseignez-la, ou '
                .'confirmez explicitement (provenance_inconnue) qu\'elle est inconnue pour publier quand même.';
        }

        $forced = false;

        if (! $flagsOk) {
            // `force` n'outrepasse QUE le critère des flags — jamais l'article,
            // la date ou la provenance : comportement déjà établi et testé
            // pour la date/l'article, désormais explicite pour tous les critères.
            if ($force && $reasons === []) {
                $forced = true;
            } else {
                $reasons['curation_status'] = "Ce document a {$blockingFlagsCount} anomalie(s) bloquante(s) non résolue(s). "
                    .'Résolvez-les avant de publier, ou utilisez « Publier quand même ».';
            }
        }

        $criteria = [
            'has_article' => $hasArticle,
            'date_entree_vigueur_ok' => $dateOk,
            'provenance_ok' => $provenanceOk,
            'blocking_flags_count' => $blockingFlagsCount,
            'flags_ok' => $flagsOk,
            'strict_flags' => $strictFlags,
        ];

        return new PublicationGuardrailResult(
            passed: $reasons === [],
            forced: $forced,
            reasons: $reasons,
            criteria: $criteria,
        );
    }

    /**
     * Écrit la preuve de validation (dashboard#119) : un enregistrement par
     * évaluation, jamais mis à jour après coup. Appelé pour un blocage (avant
     * tout `update()`) comme pour un succès (après, une fois la transition
     * réellement actée par la machine à états).
     */
    public function record(LegalDocument $document, PublicationGuardrailResult $result, ?User $actor): PublicationChecklist
    {
        return PublicationChecklist::create([
            'document_id' => $document->id,
            'actor_id' => $actor?->id,
            'target_status' => LegalDocument::STATUS_PUBLISHED,
            'outcome' => match (true) {
                $result->forced => PublicationChecklist::OUTCOME_FORCED,
                $result->passed => PublicationChecklist::OUTCOME_PASSED,
                default => PublicationChecklist::OUTCOME_BLOCKED,
            },
            'criteria' => [...$result->criteria, 'reasons' => $result->reasons],
            'document_snapshot_updated_at' => $document->updated_at,
        ]);
    }
}
