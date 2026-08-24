<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
});

function articleVersionSansEmbedding(): ArticleVersion
{
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id, 'numero_article' => '1']);

    return ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Contenu à vectoriser.',
        'validity_period' => '[2020-01-01,)',
        'embedding' => null,
    ]);
}

it('refuse la connexion en lecture seule', function () {
    $this->artisan('mibeko:process-rag', ['--connection' => 'pgsql_prod_ro'])->assertFailed();
});

it('génère les embeddings manquants sur la connexion par défaut', function () {
    $version = articleVersionSansEmbedding();

    $this->artisan('mibeko:process-rag')->assertSuccessful();

    expect($version->fresh()->embedding)->not->toBeNull();
});

it('accepte une connexion explicite et continue de trouver les versions à traiter', function () {
    $version = articleVersionSansEmbedding();

    $this->artisan('mibeko:process-rag', ['--connection' => 'pgsql'])->assertSuccessful();

    expect($version->fresh()->embedding)->not->toBeNull();
});
