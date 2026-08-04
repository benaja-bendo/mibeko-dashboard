<?php

namespace App\Observers;

use App\Ai\CorpusVersion;
use App\Jobs\GenerateDocumentExportPdfJob;
use App\Models\LegalDocument;

/**
 * Invalide le cache des réponses de l'assistant quand le périmètre publié
 * change : seul ce que l'IA peut effectivement citer (textes publiés) compte.
 */
class LegalDocumentObserver
{
    /**
     * Désactive le pré-chauffage du cache PDF d'export. Actif par défaut ;
     * la suite de tests le désactive globalement (voir Tests\TestCase) pour
     * éviter un rendu DomPDF réel à chaque publication de document — les
     * tests dédiés à cette fonctionnalité le réactivent explicitement.
     */
    public static bool $shouldSkipExportPdfWarmup = false;

    public function saved(LegalDocument $document): void
    {
        // Seule la (dé)publication change l'ensemble des textes citables.
        if ($document->wasChanged('curation_status') || $document->wasRecentlyCreated) {
            CorpusVersion::bump();
        }

        // Le PDF Mibeko exporté (partage) est mis en cache par version
        // (document->updated_at). Toute sauvegarde d'un document publié —
        // y compris une cascade de touch depuis un article — pré-chauffe ce
        // cache en tâche de fond, pour qu'un partage n'ait jamais à
        // déclencher le rendu coûteux en synchrone.
        if (! static::$shouldSkipExportPdfWarmup && $document->curation_status === LegalDocument::STATUS_PUBLISHED) {
            GenerateDocumentExportPdfJob::dispatch($document->id);
        }
    }

    public function deleted(LegalDocument $document): void
    {
        CorpusVersion::bump();
    }
}
