<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use App\Services\DocumentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
});

afterEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = false;
});

it('schedules mibeko:process-rag every ten minutes', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('mibeko:process-rag')
        ->assertSuccessful();
});

it('imports articles without generating embeddings when shouldSkipEmbeddings is true', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Code de la famille',
    ]);

    $jsonData = [
        'structure' => [
            [
                'type_unite' => 'Titre',
                'numero' => 'I',
                'titre' => 'Des personnes',
                'articles' => [
                    [
                        'numero' => '1',
                        'contenu' => 'Tout congolais jouit des droits civils.',
                    ],
                    [
                        'numero' => '2',
                        'contenu' => 'La majorité est fixée à dix-huit ans.',
                    ],
                ],
                'children' => [],
            ],
        ],
    ];

    ArticleVersionObserver::$shouldSkipEmbeddings = true;

    $importService = app(DocumentImportService::class);
    $importService->importContent($document, $jsonData);

    // Articles were imported
    expect(Article::where('document_id', $document->id)->count())->toBe(2);

    // But NO embeddings were generated
    $versions = ArticleVersion::whereHas('article', function ($q) use ($document) {
        $q->where('document_id', $document->id);
    })->get();

    expect($versions)->each(function ($version) {
        $version->embedding->toBeNull();
    });
});

it('identifies articles without embeddings via the process-rag command', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Code du travail',
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '42',
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Le contrat de travail est conclu pour une durée déterminée.',
        'validity_period' => '[2020-01-01,)',
        'embedding' => null,
    ]);

    $versionsWithoutEmbedding = ArticleVersion::whereNull('embedding')
        ->whereNotNull('contenu_texte')
        ->count();

    expect($versionsWithoutEmbedding)->toBe(1);
});
