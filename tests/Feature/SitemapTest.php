<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;

/**
 * Couvre le flux `GET /api/v1/sitemap` qui alimente le `sitemap.xml` du site :
 * documents publiés + numéros d'articles, brouillons exclus.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Cache::flush();
    DocumentType::firstOrCreate(['code' => 'CODE'], ['nom' => 'Code']);
});

it('liste les documents publiés avec leurs numéros d\'articles', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code de la Route',
        'curation_status' => 'published',
    ]);

    foreach (['premier', '2'] as $i => $number) {
        $article = Article::factory()->create([
            'document_id' => $document->id,
            'numero_article' => $number,
            'ordre_affichage' => $i + 1,
        ]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'validity_period' => '[2020-01-01,)',
        ]);
    }

    $this->getJson('/api/v1/sitemap')
        ->assertStatus(200)
        ->assertJsonPath('data.0.slug', 'code-de-la-route')
        ->assertJsonPath('data.0.articles', ['premier', '2']);
});

it('exclut les documents non publiés', function () {
    LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Brouillon de loi',
        'curation_status' => 'draft',
    ]);

    $this->getJson('/api/v1/sitemap')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});
