<?php

use App\Ai\CorpusVersion;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
});

it('keeps the same token until bumped', function () {
    $token = CorpusVersion::current();

    expect(CorpusVersion::current())->toBe($token);
});

it('changes the token when bumped', function () {
    $before = CorpusVersion::current();

    CorpusVersion::bump();

    expect(CorpusVersion::current())->not->toBe($before);
});

it('bumps the corpus version when an article content changes', function () {
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id]);
    $version = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Texte initial.',
    ]);

    $before = CorpusVersion::current();
    $version->update(['contenu_texte' => 'Texte corrigé.']);

    expect(CorpusVersion::current())->not->toBe($before);
});

it('bumps the corpus version when a document is published', function () {
    $document = LegalDocument::factory()->create(['curation_status' => 'draft']);

    $before = CorpusVersion::current();
    $document->update(['curation_status' => 'published']);

    expect(CorpusVersion::current())->not->toBe($before);
});
