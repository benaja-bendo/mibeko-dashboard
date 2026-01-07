<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('syncs articles modified after a specific date', function () {
    // OLD Article (should not be in sync)
    $oldArticle = Article::factory()->create();
    ArticleVersion::factory()->create([
        'article_id' => $oldArticle->id,
        'validity_period' => '[2023-01-01,)',
    ]);
    DB::table('articles')->where('id', $oldArticle->id)->update(['updated_at' => now()->subDays(10)]);

    // NEW Article (should be in sync)
    $newArticle = Article::factory()->create(['updated_at' => now()]);
    ArticleVersion::factory()->create([
        'article_id' => $newArticle->id,
        'validity_period' => '[2023-01-01,)',
    ]);

    $response = $this->getJson('/api/v1/sync/updates?'.http_build_query(['since' => now()->subDays(1)->toDateTimeString()]));

    $response->assertSuccessful()
        ->assertJsonCount(1, 'data.updated')
        ->assertJsonPath('data.updated.0.id', $newArticle->id);
});

it('exports a full legal document with its structure and articles', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create(['document_id' => $document->id]);
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validity_period' => '[2023-01-01,)',
        'contenu_texte' => 'Contenu de test',
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/download");

    $response->assertSuccessful()
        ->assertJsonPath('data.resource_id', $document->id)
        ->assertJsonCount(1, 'data.nodes')
        ->assertJsonCount(1, 'data.articles')
        ->assertJsonPath('data.articles.0.content', 'Contenu de test');
});

it('propagates updated_at from ArticleVersion to LegalDocument', function () {
    $document = LegalDocument::factory()->create(['updated_at' => now()->subDays(10)]);
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'updated_at' => now()->subDays(10),
    ]);

    // Create new version
    $version = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validity_period' => '[2025-01-01, infinity)',
    ]);

    // Check Article and Document updated_at
    $article->refresh();
    $document->refresh();

    // The touches property should have updated these
    expect($article->updated_at->gt(now()->subMinute()))->toBeTrue();
    expect($document->updated_at->gt(now()->subMinute()))->toBeTrue();
});
