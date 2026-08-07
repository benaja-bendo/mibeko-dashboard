<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * `mibeko:proposer-nettoyage-latex` : jamais d'écriture, seulement des
 * propositions — même contrat que `mibeko:proposer-nettoyage-ocr`.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi']);

    $this->fichiers = [
        '--out-contenus' => storage_path('app/test-latex-contenus-'.getmypid().'.json'),
        '--out-titres' => storage_path('app/test-latex-titres-'.getmypid().'.json'),
        '--out-signalements' => storage_path('app/test-latex-signalements-'.getmypid().'.json'),
    ];
});

afterEach(function () {
    foreach ($this->fichiers as $chemin) {
        if (file_exists($chemin)) {
            unlink($chemin);
        }
    }
});

function proposerLatex(array $options = []): array
{
    test()->artisan('mibeko:proposer-nettoyage-latex', array_merge(
        ['--connection' => 'pgsql'],
        test()->fichiers,
        $options,
    ))->assertExitCode(0);

    return array_map(
        fn (string $chemin) => json_decode((string) file_get_contents($chemin), true),
        test()->fichiers,
    );
}

function articleAvecContenu(string $contenu, string $statut = 'draft'): Article
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Décret n° 59-243 du 1er décembre 1959',
        'curation_status' => $statut,
    ]);

    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => 'PREAMBULE']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $contenu,
        'validity_period' => '[2020-01-01,)',
    ]);

    return $article;
}

it('propose le déséchappement du contenu d’un article', function () {
    $article = articleAvecContenu('Vu la Constitution, en son article  $1^{\text{er}}$  ;');

    $sorties = proposerLatex();
    $mien = collect($sorties['--out-contenus'])->firstWhere('id', $article->id);

    expect($mien)->not->toBeNull();
    expect($mien['content'])->toBe('Vu la Constitution, en son article 1er ;');
});

it('propose le déséchappement du titre d’un document', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Avis  $\mathfrak{n}^{\circ}$  342 de l’Office des Changes',
        'curation_status' => 'draft',
    ]);
    Article::factory()->create(['document_id' => $document->id, 'numero_article' => '1']);

    $sorties = proposerLatex();
    $mien = collect($sorties['--out-titres'])->firstWhere('id', $document->id);

    expect($mien)->not->toBeNull();
    expect($mien['titre'])->toBe('Avis n° 342 de l’Office des Changes');
    expect($mien['titre_actuel'])->toContain('mathfrak');
});

it('signale sans corriger ce qu’il ne sait pas convertir', function () {
    $article = articleAvecContenu('La redevance vaut  $\frac{a}{b}$  du chiffre d’affaires.');

    $sorties = proposerLatex();

    expect(collect($sorties['--out-contenus'])->firstWhere('id', $article->id))->toBeNull();

    $signalement = collect($sorties['--out-signalements'])->firstWhere('id', $article->id);
    expect($signalement)->not->toBeNull();
    expect($signalement['fragments'])->toContain('$\frac{a}{b}$');
});

it('ne touche pas aux montants en dollars des conventions minières', function () {
    $article = articleAvecContenu("Un apport de \$1.582.400.000\nsoit \$918.000.000 la première année.");

    $sorties = proposerLatex();

    expect(collect($sorties['--out-contenus'])->firstWhere('id', $article->id))->toBeNull();
});

it('n’écrit jamais en base — ni le contenu, ni le titre', function () {
    $article = articleAvecContenu('article  $1^{\text{er}}$  de la loi');
    $document = $article->document;

    proposerLatex();

    expect($article->activeVersion->fresh()->contenu_texte)->toContain('\text{er}');
    expect($document->fresh()->titre_officiel)->toBe('Décret n° 59-243 du 1er décembre 1959');
});

it('borne le lot avec --limit', function () {
    foreach (range(1, 3) as $rang) {
        articleAvecContenu("Article {$rang} du  \$1^{\\text{er}}\$  janvier");
    }

    $sorties = proposerLatex(['--limit' => 1]);

    expect($sorties['--out-contenus'])->toHaveCount(1);
});

it('filtre sur le statut de curation demandé', function () {
    articleAvecContenu('brouillon du  $1^{\text{er}}$  mai', 'draft');
    articleAvecContenu('publié du  $1^{\text{er}}$  mai', 'published');

    $sorties = proposerLatex(['--statut' => ['published']]);

    expect($sorties['--out-contenus'])->toHaveCount(1);
    expect($sorties['--out-contenus'][0]['content'])->toBe('publié du 1er mai');
});

it('est idempotent : rien à proposer sur un corpus déjà nettoyé', function () {
    articleAvecContenu('Vu la Constitution, en son article 1er ;');

    $sorties = proposerLatex();

    expect($sorties['--out-contenus'])->toBe([]);
    expect($sorties['--out-titres'])->toBe([]);
});
