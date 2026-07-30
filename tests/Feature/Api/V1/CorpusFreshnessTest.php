<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * L'app mobile détecte qu'un texte a changé en comparant `legal_documents
 * .updated_at`. Toute la fraîcheur du corpus hors-ligne repose donc sur la
 * remontée `$touches` : une correction d'article DOIT vieillir son document
 * parent, sinon les téléphones gardent un texte faux sans jamais le savoir.
 */
it('vieillit le document quand un de ses articles est modifié', function () {
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id]);

    $before = $document->fresh()->updated_at;
    $this->travel(2)->seconds();

    $article->update(['numero_article' => '42']);

    expect($document->fresh()->updated_at->greaterThan($before))->toBeTrue();
});

it('vieillit le document quand une version d\'article est modifiée', function () {
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id]);
    $version = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validation_status' => 'validated',
    ]);

    $before = $document->fresh()->updated_at;
    $this->travel(2)->seconds();

    // Le contenu réel vit dans la version : c'est le cas d'usage le plus
    // fréquent (correction d'un texte mal océrisé).
    $version->update(['contenu_texte' => 'Texte corrigé.']);

    expect($document->fresh()->updated_at->greaterThan($before))->toBeTrue();
});

it('vieillit le document quand un article lui est ajouté', function () {
    $document = LegalDocument::factory()->create();

    $before = $document->fresh()->updated_at;
    $this->travel(2)->seconds();

    Article::factory()->create(['document_id' => $document->id]);

    expect($document->fresh()->updated_at->greaterThan($before))->toBeTrue();
});
