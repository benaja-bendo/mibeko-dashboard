<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\ArticleVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('sync endpoint returns updated articles and deleted ids', function () {
    // Setup
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create(['document_id' => $document->id]);
    
    // Create an article that is updated recently
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
        'updated_at' => Carbon::now()->subMinutes(5)
    ]);
    
    // Create an article that was deleted recently
    $deletedArticle = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
        'updated_at' => Carbon::now()->subMinutes(5)
    ]);
    $deletedArticle->delete(); // Soft delete
    
    // Create an old article that shouldn't be returned
    $oldArticle = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
        'updated_at' => Carbon::now()->subDays(2)
    ]);

    // Action
    $since = urlencode(Carbon::now()->subDaY()->toIso8601String());
    $response = $this->getJson("/api/v1/sync/updates?since={$since}");

    // Assert
    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'updated',
                'deleted_ids'
            ],
            'meta'
        ]);

    $data = $response->json('data');
    
    // Check updated
    expect($data['updated'])->toHaveCount(1)
        ->and($data['updated'][0]['id'])->toBe($article->id);
        
    // Check deleted
    expect($data['deleted_ids'])->toContain($deletedArticle->id)
        ->and($data['deleted_ids'])->not->toContain($oldArticle->id);

});
