<?php

use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Services\Curation\Detecteurs\ArtefactTechniqueResiduel;
use App\Services\Curation\Detecteurs\D10TitreTronque;
use App\Services\Curation\Detecteurs\D1NumeroDoublon;
use App\Services\Curation\Detecteurs\D2NumeroHorsListeBlanche;
use App\Services\Curation\Detecteurs\D3ArticleAmputeDebut;
use App\Services\Curation\Detecteurs\D4EnteteJoIncruste;
use App\Services\Curation\Detecteurs\D5FragmentSommaire;
use App\Services\Curation\Detecteurs\D6LatexResiduel;
use App\Services\Curation\Detecteurs\D7ContenuQuasiVide;
use App\Services\Curation\Detecteurs\D8ConfusionOcr;
use App\Services\Curation\Detecteurs\D9BalisageHtmlBrut;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Article + version courante (période ouverte), comme AnomalyDetectionTest. */
function creerArticle(LegalDocument $document, string $numero, string $contenu): void
{
    $article = $document->articles()->create([
        'numero_article' => $numero,
        'ordre_affichage' => 1,
    ]);
    $article->versions()->create([
        'contenu_texte' => $contenu,
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
    ]);
}

// ── D1 — numéro portant un marqueur de doublon ─────────────────────────────

it('D1 flags an article number carrying a doublon marker', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '12-doublon', 'Contenu quelconque, suffisamment long pour ne rien flaguer d\'autre.');

    $candidats = (new D1NumeroDoublon)->detecter($document);

    expect($candidats)->toHaveCount(1)->and($candidats[0]['article_id'])->not->toBeNull();
});

it('D1 does not flag a normal article number', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '12', 'Contenu quelconque, suffisamment long pour ne rien flaguer d\'autre.');

    expect((new D1NumeroDoublon)->detecter($document))->toBeEmpty();
});

// ── D2 — numéro hors liste blanche ─────────────────────────────────────────

it('D2 flags a malformed article number', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '3- L', 'Contenu quelconque, suffisamment long pour ne rien flaguer d\'autre.');

    expect((new D2NumeroHorsListeBlanche)->detecter($document))->toHaveCount(1);
});

it('D2 accepts numbers in the whitelist', function () {
    $document = LegalDocument::factory()->create();
    foreach (['12', 'PREAMBULE', '1er', 'unique', 'TABLEAU_1', '12 bis', '12-13'] as $numero) {
        creerArticle($document, $numero, 'Contenu quelconque, suffisamment long pour ne rien flaguer d\'autre.');
    }

    expect((new D2NumeroHorsListeBlanche)->detecter($document))->toBeEmpty();
});

// ── D3 — article amputé du début ───────────────────────────────────────────

it('D3 flags content starting with a lowercase letter', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '5', 'physiques et morales exerçant une activité commerciale.');

    expect((new D3ArticleAmputeDebut)->detecter($document))->toHaveCount(1);
});

it('D3 does not flag content starting with an uppercase letter, nor a legitimate "1er" continuation', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Les personnes physiques et morales sont concernées.');
    creerArticle($document, '2', 'er alinéa du présent article ne s\'applique pas.');

    expect((new D3ArticleAmputeDebut)->detecter($document))->toBeEmpty();
});

it('D3 also flags a PREAMBULE that starts with a lowercase letter', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, 'PREAMBULE', 'ratification de la convention n° 154 de l\'OIT.');

    $candidats = (new D3ArticleAmputeDebut)->detecter($document);

    expect($candidats)->toHaveCount(1);
});

// ── D4 — en-tête de JO incrusté ─────────────────────────────────────────────

it('D4 flags an embedded official journal masthead', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', "Texte de l'article.\n123\nJournal officiel\nsuite du texte.");

    expect((new D4EnteteJoIncruste)->detecter($document))->toHaveCount(1);
});

it('D4 does not flag clean content', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Un texte tout à fait normal, sans mention de journal officiel incrustée.');

    expect((new D4EnteteJoIncruste)->detecter($document))->toBeEmpty();
});

// ── D5 — fragment de sommaire ───────────────────────────────────────────────

it('D5 flags a short summary-line fragment ending in a page number', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Loi n° 24-80 portant Code minier Page. 1081');

    expect((new D5FragmentSommaire)->detecter($document))->toHaveCount(1);
});

it('D5 does not flag a long article that happens to end with a number', function () {
    $document = LegalDocument::factory()->create();
    $contenuLong = str_repeat('Disposition complète et longue. ', 10).'Page. 1081';
    creerArticle($document, '1', $contenuLong);

    expect((new D5FragmentSommaire)->detecter($document))->toBeEmpty();
});

// ── D6 — LaTeX résiduel ──────────────────────────────────────────────────────

it('D6 flags residual LaTeX notation', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Décret $\mathfrak{n}^{\circ}$ 59-180 du 21 août 1959.');

    expect((new D6LatexResiduel)->detecter($document))->toHaveCount(1);
});

it('D6 does not flag content without dollar signs', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Décret n° 59-180 du 21 août 1959.');

    expect((new D6LatexResiduel)->detecter($document))->toBeEmpty();
});

// ── D7 — contenu quasi vide ──────────────────────────────────────────────────

it('D7 flags near-empty content', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Page. 12');

    expect((new D7ContenuQuasiVide)->detecter($document))->toHaveCount(1);
});

it('D7 does not flag substantial content', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Ceci est un contenu tout à fait substantiel, largement au-dessus du seuil.');

    expect((new D7ContenuQuasiVide)->detecter($document))->toBeEmpty();
});

// ── D8 — confusion OCR I/1/0 ─────────────────────────────────────────────────

it('D8 flags a likely OCR letter/digit confusion', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Est déclarée généra1e la présente mesure administrative.');

    expect((new D8ConfusionOcr)->detecter($document))->toHaveCount(1);
});

it('D8 does not flag correctly spelled content', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Est déclarée générale la présente mesure administrative.');

    expect((new D8ConfusionOcr)->detecter($document))->toBeEmpty();
});

// ── D9 — balisage HTML brut ──────────────────────────────────────────────────

it('D9 flags raw HTML markup', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Par extension<sup>er</sup>. — Le présent article s\'applique.');

    expect((new D9BalisageHtmlBrut)->detecter($document))->toHaveCount(1);
});

it('D9 does not flag plain text', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Par extension. Le présent article s\'applique sans balisage.');

    expect((new D9BalisageHtmlBrut)->detecter($document))->toBeEmpty();
});

// ── D10 — titre tronqué (document, pas article) ────────────────────────────

it('D10 flags a title ending with a function word', function () {
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Loi n° 9-2023 du 10 mai 2023 autorisant la']);

    $candidats = (new D10TitreTronque)->detecter($document);

    expect($candidats)->toHaveCount(1)->and($candidats[0])->not->toHaveKey('article_id');
});

it('D10 does not flag a complete title', function () {
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Loi n° 9-2023 du 10 mai 2023 autorisant la ratification']);

    expect((new D10TitreTronque)->detecter($document))->toBeEmpty();
});

// ── Artefact technique résiduel ──────────────────────────────────────────────

it('flags a residual pipeline marker in the content', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', "Texte de l'article.\n[[MIBEKO_PAGE:3]]\nsuite du texte.");

    expect((new ArtefactTechniqueResiduel)->detecter($document))->toHaveCount(1);
});

it('does not flag content without a residual pipeline marker', function () {
    $document = LegalDocument::factory()->create();
    creerArticle($document, '1', 'Un texte tout à fait propre, sans marqueur technique.');

    expect((new ArtefactTechniqueResiduel)->detecter($document))->toBeEmpty();
});
