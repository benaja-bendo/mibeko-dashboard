<?php

use App\Jobs\GenerateDocumentExportPdfJob;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use App\Services\DocumentExportPdfService;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;

/**
 * Couvre GenerateDocumentExportPdfJob : pré-chauffage en tâche de fond du
 * cache PDF d'export (voir DocumentExportPdfService), dispatché par
 * LegalDocumentObserver à chaque sauvegarde d'un document publié — pour que
 * le rendu DomPDF, coûteux sur un gros document, ne bloque jamais un partage.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Storage::fake('s3');
});

it('génère et met en cache le PDF d\'un document publié', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);

    (new GenerateDocumentExportPdfJob($document->id))->handle(app(DocumentExportPdfService::class));

    expect(Storage::disk('s3')->files("exports/documents/{$document->id}"))->toHaveCount(1);
});

it('ne fait rien pour un document introuvable', function () {
    (new GenerateDocumentExportPdfJob('00000000-0000-0000-0000-000000000000'))
        ->handle(app(DocumentExportPdfService::class));

    expect(Storage::disk('s3')->allFiles('exports/documents'))->toBeEmpty();
});

it('ne fait rien pour un document non publié', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);

    (new GenerateDocumentExportPdfJob($document->id))->handle(app(DocumentExportPdfService::class));

    expect(Storage::disk('s3')->allFiles("exports/documents/{$document->id}"))->toBeEmpty();
});

it('est idempotent : ne régénère pas si le cache est déjà à jour', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);
    $exporter = app(DocumentExportPdfService::class);

    (new GenerateDocumentExportPdfJob($document->id))->handle($exporter);

    $path = Storage::disk('s3')->files("exports/documents/{$document->id}")[0];
    Storage::disk('s3')->put($path, 'CACHE-HIT-MARKER');

    (new GenerateDocumentExportPdfJob($document->id))->handle($exporter);

    expect(Storage::disk('s3')->get($path))->toBe('CACHE-HIT-MARKER');
});
