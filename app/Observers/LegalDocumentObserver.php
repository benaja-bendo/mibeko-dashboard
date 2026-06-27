<?php

namespace App\Observers;

use App\Ai\CorpusVersion;
use App\Models\LegalDocument;

/**
 * Invalide le cache des réponses de l'assistant quand le périmètre publié
 * change : seul ce que l'IA peut effectivement citer (textes publiés) compte.
 */
class LegalDocumentObserver
{
    public function saved(LegalDocument $document): void
    {
        // Seule la (dé)publication change l'ensemble des textes citables.
        if ($document->wasChanged('curation_status') || $document->wasRecentlyCreated) {
            CorpusVersion::bump();
        }
    }

    public function deleted(LegalDocument $document): void
    {
        CorpusVersion::bump();
    }
}
