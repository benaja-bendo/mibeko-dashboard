<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can download a legal document for offline sync', function () {
    $document = LegalDocument::factory()->create();

    $node = StructureNode::factory()->create([
        'document_id' => $document->id,
        'titre' => 'Titre 1',
        'type_unite' => 'TITRE',
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validation_status' => 'validated',
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/download");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resource_id', $document->id)
        ->assertJsonCount(1, 'data.nodes')
        ->assertJsonCount(1, 'data.articles');
});
