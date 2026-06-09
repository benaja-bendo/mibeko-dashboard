<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
});

it('soft-delete un document masque aussi ses articles', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->count(2)->create(['document_id' => $document->id]);

    $document->delete();

    expect(Article::count())->toBe(0)
        ->and(Article::withTrashed()->count())->toBe(2);
});

it('restaure les articles quand le document est restauré', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->count(2)->create(['document_id' => $document->id]);

    $document->delete();
    expect(Article::count())->toBe(0);

    $document->restore();
    expect(Article::count())->toBe(2);
});

it('exclut les articles d’un document supprimé du sélecteur de cibles de relation', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '420',
    ]);

    $document->delete();

    $this->getJson('/api/v1/relations/search?q=420')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('renvoie 404 sur l’arbre d’un document supprimé', function () {
    $document = LegalDocument::factory()->create();
    $document->delete();

    $this->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertNotFound();
});

it('garde l’arbre d’un document actif accessible', function () {
    $document = LegalDocument::factory()->create();

    $this->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertOk();
});
