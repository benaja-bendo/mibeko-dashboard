<?php

use App\Models\DocumentControleRun;
use App\Models\LegalDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a controle run for a document', function () {
    $document = LegalDocument::factory()->create();

    $run = DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'version_jeu' => 'v3',
        'resultat' => DocumentControleRun::RESULTAT_OK,
        'resultats' => ['d1_doublon_numero' => 0],
    ]);

    expect($run->document->id)->toBe($document->id)
        ->and($run->resultats)->toBe(['d1_doublon_numero' => 0]);
});

it('is append-only : a second run does not overwrite the first', function () {
    // Dates explicitement distinctes : deux create() consécutifs peuvent
    // tomber sur la même microseconde et rendre l'ordre par `date` seul
    // non déterministe — piège pour ce test, pas pour le code sous test.
    $document = LegalDocument::factory()->create();

    DocumentControleRun::factory()->create([
        'document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC, 'date' => now()->subMinute(),
    ]);
    DocumentControleRun::factory()->create([
        'document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_OK, 'date' => now(),
    ]);

    expect(DocumentControleRun::where('document_id', $document->id)->count())->toBe(2);

    $dernier = DocumentControleRun::where('document_id', $document->id)->orderByDesc('date')->first();
    expect($dernier->resultat)->toBe(DocumentControleRun::RESULTAT_OK);
});

it('rejects a resultat outside ok/echec/incomplet', function () {
    $document = LegalDocument::factory()->create();

    expect(fn () => DocumentControleRun::factory()->create([
        'document_id' => $document->id,
        'resultat' => 'invalide',
    ]))->toThrow(QueryException::class);
});

it('cascades on document deletion (force delete)', function () {
    $document = LegalDocument::factory()->create();
    $run = DocumentControleRun::factory()->create(['document_id' => $document->id]);

    $document->forceDelete();

    expect(DocumentControleRun::whereKey($run->id)->exists())->toBeFalse();
});
