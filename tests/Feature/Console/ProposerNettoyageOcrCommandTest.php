<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * `mibeko:proposer-nettoyage-ocr` : jamais d'écriture, seulement des
 * propositions — voir docs/_archive/audit-prod-2026-08-04.md § 6.2.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi']);
});

it('corrige les ligatures ﬁ/ﬂ/ﬀ/ﬃ/ﬄ dans le titre d\'un document publié', function () {
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => "Loi n° 38-2023 portant loi de \u{FB01} nances rectiﬁ cative",
        'curation_status' => 'published',
    ]);
    Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1']);

    $cheminTitres = storage_path('app/test-nettoyage-titres.json');
    $cheminContenus = storage_path('app/test-nettoyage-contenus.json');
    $this->artisan('mibeko:proposer-nettoyage-ocr', [
        '--connection' => 'pgsql', '--out-titres' => $cheminTitres, '--out-contenus' => $cheminContenus,
    ])->assertExitCode(0);

    $titres = json_decode((string) file_get_contents($cheminTitres), true);
    $mien = collect($titres)->firstWhere('id', $doc->id);

    expect($mien)->not->toBeNull();
    expect($mien['titre'])->toBe('Loi n° 38-2023 portant loi de finances rectificative');

    unlink($cheminTitres);
    unlink($cheminContenus);
});

it('corrige le thorn islandais Þ/þ, qui se substitue à « fi » dans ce corpus', function () {
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi n° 32-2025 Þ xant les modalités',
        'curation_status' => 'published',
    ]);
    Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1']);

    $cheminTitres = storage_path('app/test-nettoyage-titres-2.json');
    $cheminContenus = storage_path('app/test-nettoyage-contenus-2.json');
    $this->artisan('mibeko:proposer-nettoyage-ocr', [
        '--connection' => 'pgsql', '--out-titres' => $cheminTitres, '--out-contenus' => $cheminContenus,
    ])->assertExitCode(0);

    $titres = json_decode((string) file_get_contents($cheminTitres), true);
    $mien = collect($titres)->firstWhere('id', $doc->id);

    expect($mien['titre'])->toBe('Loi n° 32-2025 fixant les modalités');

    unlink($cheminTitres);
    unlink($cheminContenus);
});

it('retire le marqueur de pagination [[MIBEKO_PAGE:N]] du contenu d\'un article publié', function () {
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Décret n° 80-550 portant nomination',
        'curation_status' => 'published',
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => "Nul ne peut recevoir des apprentis mineurs.\n[[MIBEKO_PAGE:1095]]\nActes en abrégé 1096",
        'validity_period' => '[2020-01-01,)',
    ]);

    $cheminTitres = storage_path('app/test-nettoyage-titres-3.json');
    $cheminContenus = storage_path('app/test-nettoyage-contenus-3.json');
    $this->artisan('mibeko:proposer-nettoyage-ocr', [
        '--connection' => 'pgsql', '--out-titres' => $cheminTitres, '--out-contenus' => $cheminContenus,
    ])->assertExitCode(0);

    $contenus = json_decode((string) file_get_contents($cheminContenus), true);
    $mien = collect($contenus)->firstWhere('id', $article->id);

    expect($mien)->not->toBeNull();
    expect($mien['content'])->not->toContain('MIBEKO_PAGE');
    expect($mien['content'])->toContain('Nul ne peut recevoir des apprentis mineurs.');
    expect($mien['content'])->toContain('Actes en abrégé 1096');

    unlink($cheminTitres);
    unlink($cheminContenus);
});

it('n\'écrit jamais en base — ni le titre ni le contenu', function () {
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi n° 1 Þ xant quelque chose',
        'curation_status' => 'published',
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Contenu avec [[MIBEKO_PAGE:12]] marqueur.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $cheminTitres = storage_path('app/test-nettoyage-titres-4.json');
    $cheminContenus = storage_path('app/test-nettoyage-contenus-4.json');
    $this->artisan('mibeko:proposer-nettoyage-ocr', [
        '--connection' => 'pgsql', '--out-titres' => $cheminTitres, '--out-contenus' => $cheminContenus,
    ])->assertExitCode(0);

    expect($doc->fresh()->titre_officiel)->toContain('Þ');
    expect($article->activeVersion->fresh()->contenu_texte)->toContain('MIBEKO_PAGE');

    unlink($cheminTitres);
    unlink($cheminContenus);
});

it('ignore les documents et articles déjà propres', function () {
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi n° 1 fixant quelque chose de propre',
        'curation_status' => 'published',
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1']);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Un contenu déjà propre, sans aucun artefact.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $cheminTitres = storage_path('app/test-nettoyage-titres-5.json');
    $cheminContenus = storage_path('app/test-nettoyage-contenus-5.json');
    $this->artisan('mibeko:proposer-nettoyage-ocr', [
        '--connection' => 'pgsql', '--out-titres' => $cheminTitres, '--out-contenus' => $cheminContenus,
    ])->assertExitCode(0);

    $titres = json_decode((string) file_get_contents($cheminTitres), true);
    $contenus = json_decode((string) file_get_contents($cheminContenus), true);

    expect(collect($titres)->firstWhere('id', $doc->id))->toBeNull();
    expect(collect($contenus)->firstWhere('id', $article->id))->toBeNull();

    unlink($cheminTitres);
    unlink($cheminContenus);
});

it('ignore les documents en brouillon (hors périmètre — voir mibeko:corriger-titres-jo)', function () {
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi n° 1 Þ xant quelque chose',
        'curation_status' => 'draft',
    ]);
    Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1']);

    $cheminTitres = storage_path('app/test-nettoyage-titres-6.json');
    $cheminContenus = storage_path('app/test-nettoyage-contenus-6.json');
    $this->artisan('mibeko:proposer-nettoyage-ocr', [
        '--connection' => 'pgsql', '--out-titres' => $cheminTitres, '--out-contenus' => $cheminContenus,
    ])->assertExitCode(0);

    $titres = json_decode((string) file_get_contents($cheminTitres), true);
    expect(collect($titres)->firstWhere('id', $doc->id))->toBeNull();

    unlink($cheminTitres);
    unlink($cheminContenus);
});
