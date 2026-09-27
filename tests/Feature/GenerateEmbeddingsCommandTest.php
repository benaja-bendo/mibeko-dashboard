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

function articleVersionSansEmbedding(?LegalDocument $document = null, array $attributs = []): ArticleVersion
{
    $document ??= LegalDocument::factory()->create();
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => (string) (Article::withTrashed()->where('document_id', $document->id)->count() + 1),
    ]);

    return ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Contenu à vectoriser.',
        'validity_period' => '[2020-01-01,)',
        'embedding' => null,
        ...$attributs,
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

it('ignore les versions d\'un article retiré, que sa suppression douce ne touche pas', function () {
    $version = articleVersionSansEmbedding();
    $version->article->delete();

    // Le constat de dashboard#210 : la version reste vivante et sans embedding.
    expect($version->fresh())->not->toBeNull();

    $this->artisan('mibeko:process-rag')->assertSuccessful();

    expect($version->fresh()->embedding)->toBeNull();
    Embeddings::assertNothingGenerated();
});

it('ignore les versions d\'un document retiré hors Eloquent, sans cascade sur ses articles', function () {
    $version = articleVersionSansEmbedding();
    // Comme une écriture du pipeline Python : aucun événement, les articles
    // restent vivants. Seul le filtre sur le document les écarte.
    LegalDocument::query()->whereKey($version->article->document_id)->update(['deleted_at' => now()]);

    $this->artisan('mibeko:process-rag')->assertSuccessful();

    expect($version->fresh()->embedding)->toBeNull();
    Embeddings::assertNothingGenerated();
});

it('ignore une version retirée', function () {
    $version = articleVersionSansEmbedding();
    $version->delete();

    $this->artisan('mibeko:process-rag')->assertSuccessful();

    Embeddings::assertNothingGenerated();
});

it('traite d\'abord les versions les plus récentes quand --limit tronque', function () {
    $ancienne = articleVersionSansEmbedding(attributs: ['created_at' => now()->subDays(30)]);
    $recente = articleVersionSansEmbedding(attributs: ['created_at' => now()->subDay()]);
    $intermediaire = articleVersionSansEmbedding(attributs: ['created_at' => now()->subDays(10)]);

    $this->artisan('mibeko:process-rag', ['--limit' => 2])->assertSuccessful();

    expect($recente->fresh()->embedding)->not->toBeNull()
        ->and($intermediaire->fresh()->embedding)->not->toBeNull()
        ->and($ancienne->fresh()->embedding)->toBeNull();
});

it('ne vectorise que le document demandé avec --document', function () {
    $cible = articleVersionSansEmbedding();
    $autre = articleVersionSansEmbedding(attributs: ['created_at' => now()->addMinute()]);

    $this->artisan('mibeko:process-rag', ['--document' => $cible->article->document_id])
        ->assertSuccessful();

    expect($cible->fresh()->embedding)->not->toBeNull()
        ->and($autre->fresh()->embedding)->toBeNull();
});

it('garde le filtre des articles retirés avec --document', function () {
    $document = LegalDocument::factory()->create();
    $vivante = articleVersionSansEmbedding($document);
    $retiree = articleVersionSansEmbedding($document);
    $retiree->article->delete();

    $this->artisan('mibeko:process-rag', ['--document' => $document->id])->assertSuccessful();

    expect($vivante->fresh()->embedding)->not->toBeNull()
        ->and($retiree->fresh()->embedding)->toBeNull();
});

it('refuse un --document qui n\'est pas un UUID', function () {
    $this->artisan('mibeko:process-rag', ['--document' => 'code-du-travail'])->assertFailed();

    Embeddings::assertNothingGenerated();
});

it('refuse un --document introuvable ou retiré', function () {
    $retire = LegalDocument::factory()->create();
    $retire->delete();

    $this->artisan('mibeko:process-rag', ['--document' => '00000000-0000-0000-0000-000000000000'])->assertFailed();
    $this->artisan('mibeko:process-rag', ['--document' => $retire->id])->assertFailed();

    Embeddings::assertNothingGenerated();
});
