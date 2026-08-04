<?php

use App\Jobs\GenerateDocumentExportPdfJob;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use App\Observers\LegalDocumentObserver;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

/**
 * Couvre le déclenchement du pré-chauffage du cache PDF d'export (voir
 * GenerateDocumentExportPdfJob et DocumentExportPdfService) depuis
 * LegalDocumentObserver.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    // Réactivé explicitement : désactivé par défaut dans toute la suite
    // (voir Tests\TestCase) pour ne pas déclencher un rendu DomPDF réel sur
    // les nombreux autres tests qui publient un document en passant.
    LegalDocumentObserver::$shouldSkipExportPdfWarmup = false;

    Queue::fake();
});

it('dispatche le pré-chauffage quand un document est publié', function () {
    LegalDocument::factory()->create(['curation_status' => 'published']);

    Queue::assertPushed(GenerateDocumentExportPdfJob::class);
});

it('ne dispatche rien pour un document en brouillon', function () {
    LegalDocument::factory()->create(['curation_status' => 'draft']);

    Queue::assertNotPushed(GenerateDocumentExportPdfJob::class);
});

it('ne dispatche rien quand le pré-chauffage est désactivé', function () {
    LegalDocumentObserver::$shouldSkipExportPdfWarmup = true;

    LegalDocument::factory()->create(['curation_status' => 'published']);

    Queue::assertNotPushed(GenerateDocumentExportPdfJob::class);
});
