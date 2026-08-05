<?php

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('visite un document sans article mais à l\'extraction déclarée terminée', function () {
    // Régression : `whereHas('articles')` seul excluait du scan en masse les
    // documents sans le moindre article — exactement les 32 actes JO du
    // 02/08/2026 que le contrôle emptyExtraction existe pour attraper.
    $vide = LegalDocument::factory()->create(['extraction_status' => 'completed']);

    $this->artisan('documents:detect-anomalies')->assertSuccessful();

    expect(CurationFlag::where('document_id', $vide->id)->where('type_probleme', 'extraction_vide')->exists())->toBeTrue();
});

it('n\'inclut pas un document encore en cours de traitement, sans article', function () {
    $enCours = LegalDocument::factory()->create(['extraction_status' => 'processing']);

    $this->artisan('documents:detect-anomalies')->assertSuccessful();

    expect(CurationFlag::where('document_id', $enCours->id)->exists())->toBeFalse();
});

it('continue de visiter les documents porteurs d\'articles', function () {
    $document = LegalDocument::factory()->create(['extraction_status' => 'processing']);
    Article::factory()->create(['document_id' => $document->id]); // sans version → contenu vide

    $this->artisan('documents:detect-anomalies')->assertSuccessful();

    // Le document est bien VISITÉ (son article sans contenu est signalé) : le
    // nouveau périmètre ne restreint rien pour ce cas déjà couvert avant ce fix.
    expect(CurationFlag::where('document_id', $document->id)->where('type_probleme', 'article_vide')->exists())->toBeTrue();
});
