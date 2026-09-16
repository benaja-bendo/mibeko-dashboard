<?php

use App\Models\DocumentControleRun;
use App\Models\DocumentRelecturePreuve;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a reading proof tied to a document and its controle run', function () {
    $document = LegalDocument::factory()->create();
    $run = DocumentControleRun::factory()->create(['document_id' => $document->id]);
    $actor = User::factory()->create();

    $preuve = DocumentRelecturePreuve::factory()->create([
        'document_id' => $document->id,
        'actor_id' => $actor->id,
        'document_controle_run_id' => $run->id,
        'points_vus' => ['article-1', 'article-2'],
        'sondage_articles' => ['article-3', 'article-4'],
        'sondage_confirmes' => ['article-3'],
    ]);

    expect($preuve->document->id)->toBe($document->id)
        ->and($preuve->actor->id)->toBe($actor->id)
        ->and($preuve->controleRun->id)->toBe($run->id)
        ->and($preuve->points_vus)->toBe(['article-1', 'article-2']);
});

it('is append-only : a second proof does not overwrite the first', function () {
    $document = LegalDocument::factory()->create();
    $run = DocumentControleRun::factory()->create(['document_id' => $document->id]);

    DocumentRelecturePreuve::factory()->create(['document_id' => $document->id, 'document_controle_run_id' => $run->id]);
    DocumentRelecturePreuve::factory()->create(['document_id' => $document->id, 'document_controle_run_id' => $run->id]);

    expect($document->relecturePreuves()->count())->toBe(2);
});

it('sondageComplet is true only when every drawn article has been confirmed', function () {
    $incomplet = DocumentRelecturePreuve::factory()->make([
        'sondage_articles' => ['a', 'b', 'c'],
        'sondage_confirmes' => ['a', 'b'],
    ]);
    $complet = DocumentRelecturePreuve::factory()->make([
        'sondage_articles' => ['a', 'b', 'c'],
        'sondage_confirmes' => ['a', 'b', 'c'],
    ]);
    $vide = DocumentRelecturePreuve::factory()->make([
        'sondage_articles' => [],
        'sondage_confirmes' => [],
    ]);

    expect($incomplet->sondageComplet())->toBeFalse()
        ->and($complet->sondageComplet())->toBeTrue()
        ->and($vide->sondageComplet())->toBeFalse();
});

it('cascades on document deletion (force delete)', function () {
    $document = LegalDocument::factory()->create();
    $run = DocumentControleRun::factory()->create(['document_id' => $document->id]);
    $preuve = DocumentRelecturePreuve::factory()->create(['document_id' => $document->id, 'document_controle_run_id' => $run->id]);

    $document->forceDelete();

    expect(DocumentRelecturePreuve::whereKey($preuve->id)->exists())->toBeFalse();
});
