<?php

use App\Models\ExtractionRun;
use App\Models\LegalDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepte tous les statuts de run, y compris ceux du replay non-destructif', function (string $status) {
    $document = LegalDocument::factory()->create();

    $run = ExtractionRun::create([
        'document_id' => $document->id,
        'source' => 'PARSING',
        'status' => $status,
    ]);

    expect($run->refresh()->status)->toBe($status);
})->with([
    'queued',
    'running',
    'succeeded',
    'failed',
    'partial',
    'needs_review',
    'discarded',
]);

it('rejette un statut hors vocabulaire via la contrainte CHECK', function () {
    $document = LegalDocument::factory()->create();

    ExtractionRun::create([
        'document_id' => $document->id,
        'source' => 'PARSING',
        'status' => 'inconnu',
    ]);
})->throws(QueryException::class, 'extraction_runs_status_check');
