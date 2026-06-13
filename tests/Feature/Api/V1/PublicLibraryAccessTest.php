<?php

use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;

/**
 * La consultation de la Bibliothèque (accueil, recherche, autocomplétion)
 * est publique : partagée entre le web pro et le mobile, elle ne doit pas
 * exiger de compte. Seule l'IA (explain/synthesis) reste authentifiée.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Cache::flush();
});

it('serves the library home without authentication', function () {
    $this->getJson('/api/v1/library/home')
        ->assertOk()
        ->assertJsonPath('success', true);
});

it('serves the library search without authentication', function () {
    $this->getJson('/api/v1/library/search?q=travail')
        ->assertOk();
});

it('serves the library suggestions without authentication', function () {
    $this->getJson('/api/v1/library/suggest?q=trav')
        ->assertOk();
});

it('keeps the library ai endpoints behind authentication', function () {
    $this->postJson('/api/v1/library/explain', ['article_id' => 'x'])
        ->assertUnauthorized();

    $this->postJson('/api/v1/library/synthesis', ['q' => 'travail'])
        ->assertUnauthorized();
});
