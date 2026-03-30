<?php

use App\Contracts\AiServiceInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Disable automatic embedding generation to speed up tests and avoid API calls
    ArticleVersionObserver::$shouldSkipEmbeddings = true;

    // Mock the AI service to avoid real API calls during search
    $this->mock(AiServiceInterface::class, function (MockInterface $mock) {
        // Return a dummy embedding vector (e.g., 1024 or 1536 dimensions, depending on the model, we just need a valid array)
        $mock->shouldReceive('generateEmbedding')->andReturn(array_fill(0, 1024, 0.1));
        $mock->shouldReceive('generateChatCompletion')->andReturn('Réponse IA mockée');
    });

    $this->typeLoi = DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    $this->typeDec = DocumentType::create(['code' => 'DEC', 'nom' => 'Décret']);

    $this->doc1 = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le travail',
    ]);

    $this->doc2 = LegalDocument::factory()->create([
        'type_code' => 'DEC',
        'titre_officiel' => 'Décret sur la santé',
    ]);

    $this->article1 = Article::factory()->create([
        'document_id' => $this->doc1->id,
        'numero_article' => '123',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article1->id,
        'contenu_texte' => 'Ceci est un article sur le licenciement.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $this->article2 = Article::factory()->create([
        'document_id' => $this->doc2->id,
        'numero_article' => '456',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article2->id,
        'contenu_texte' => 'Ceci est un article sur la sécurité.',
        'validity_period' => '[2020-01-01,)',
    ]);
});

it('can search articles by content (without RAG)', function () {
    $response = $this->getJson('/api/v1/articles/search?q=licenciement');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '123')
        ->assertJsonPath('data.0.content', 'Ceci est un article sur le licenciement.');
});

it('can search articles by number', function () {
    $response = $this->getJson('/api/v1/articles/search?q=456');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '456');
});

it('can filter search results by document type', function () {
    // Both contain 'article' in content, but different types
    $response = $this->getJson('/api/v1/articles/search?q=article&type=LOI');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document_type', 'LOI');

    $response = $this->getJson('/api/v1/articles/search?q=article&type=DEC');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document_type', 'DEC');
});

it('returns the correct resource structure without RAG', function () {
    $response = $this->getJson('/api/v1/articles/search?q=licenciement');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'number',
                    'order',
                    'content',
                    'document_id',
                    'document_title',
                    'document_type',
                    'node_title',
                    'breadcrumb',
                    'validation_status',
                ],
            ],
            'pagination' => [
                'total',
                'per_page',
                'current_page',
                'last_page',
            ],
        ]);
});

it('returns the correct resource structure with RAG', function () {
    // Adding rag=true to force RAG execution
    $response = $this->getJson('/api/v1/articles/search?q=licenciement&rag=true');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'answer',
                'sources' => [
                    '*' => [
                        'id',
                        'number',
                        'order',
                        'content',
                        'document_id',
                        'document_title',
                        'document_type',
                        'node_title',
                        'breadcrumb',
                        'validation_status',
                    ],
                ],
                'pagination' => [
                    'total',
                    'per_page',
                    'current_page',
                    'last_page',
                ],
            ],
        ]);
});

it('requires a search query of at least 2 characters', function () {
    $response = $this->getJson('/api/v1/articles/search?q=a');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});
