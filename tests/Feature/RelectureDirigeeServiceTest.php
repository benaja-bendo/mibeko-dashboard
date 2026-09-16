<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Services\Curation\RelectureDirigeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creerArticleOrdonne(LegalDocument $document, int $ordre, string $numero): Article
{
    return Article::factory()->create([
        'document_id' => $document->id,
        'ordre_affichage' => $ordre,
        'numero_article' => $numero,
    ]);
}

// ── points d'observation obligatoires ──────────────────────────────────────

it('always includes the first and last article', function () {
    $document = LegalDocument::factory()->create();
    $a1 = creerArticleOrdonne($document, 1, '1');
    creerArticleOrdonne($document, 2, '2');
    $a3 = creerArticleOrdonne($document, 3, '3');

    $points = (new RelectureDirigeeService)->pointsObligatoires($document);

    expect($points)->toContain($a1->id, $a3->id);
});

it('includes the two articles around a sequence break', function () {
    $document = LegalDocument::factory()->create();
    creerArticleOrdonne($document, 1, '1');
    $avantRupture = creerArticleOrdonne($document, 2, '2');
    $apresRupture = creerArticleOrdonne($document, 3, '5'); // saute 3 et 4
    creerArticleOrdonne($document, 4, '6');

    $points = (new RelectureDirigeeService)->pointsObligatoires($document);

    expect($points)->toContain($avantRupture->id, $apresRupture->id);
});

it('does not flag a clean consecutive sequence as a break', function () {
    $document = LegalDocument::factory()->create();
    $a1 = creerArticleOrdonne($document, 1, '1');
    creerArticleOrdonne($document, 2, '2');
    $a3 = creerArticleOrdonne($document, 3, '3');

    $points = (new RelectureDirigeeService)->pointsObligatoires($document);

    // Premier + dernier seulement, aucune rupture entre les deux.
    expect($points->values()->all())->toEqualCanonicalizing([$a1->id, $a3->id]);
});

it('ignores non-numeric article numbers when detecting sequence breaks', function () {
    $document = LegalDocument::factory()->create();
    $a1 = creerArticleOrdonne($document, 1, '1');
    creerArticleOrdonne($document, 2, 'PREAMBULE');
    $a3 = creerArticleOrdonne($document, 3, '2');

    $points = (new RelectureDirigeeService)->pointsObligatoires($document);

    // PREAMBULE (non numérique) ne casse pas la continuité 1 → 2.
    expect($points->values()->all())->toEqualCanonicalizing([$a1->id, $a3->id]);
});

it('returns an empty collection for a document without articles', function () {
    $document = LegalDocument::factory()->create();

    expect((new RelectureDirigeeService)->pointsObligatoires($document))->toBeEmpty();
});

// ── sondage déterministe ────────────────────────────────────────────────────

it('draws the same sample twice for the same document and version', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->count(30)->create(['document_id' => $document->id]);
    $service = new RelectureDirigeeService;

    $premier = $service->sondage($document, 'v3')->values()->all();
    $second = $service->sondage($document, 'v3')->values()->all();

    expect($premier)->toBe($second)->and($premier)->toHaveCount(15);
});

it('draws a different sample for a different jeu version', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->count(30)->create(['document_id' => $document->id]);
    $service = new RelectureDirigeeService;

    $v3 = $service->sondage($document, 'v3')->values()->all();
    $v4 = $service->sondage($document, 'v4')->values()->all();

    expect($v3)->not->toBe($v4);
});

it('returns every article when there are fewer than the sample size', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->count(5)->create(['document_id' => $document->id]);

    $sondage = (new RelectureDirigeeService)->sondage($document, 'v3');

    expect($sondage)->toHaveCount(5);
});
