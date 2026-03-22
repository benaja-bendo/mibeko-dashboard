<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('debugging search results', function () {
    $typeLoi = DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);

    $doc1 = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le travail',
    ]);

    $article1 = Article::factory()->create([
        'document_id' => $doc1->id,
        'numero_article' => '123',
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article1->id,
        'contenu_texte' => 'Ceci est un article sur le licenciement.',
        'validation_status' => 'validated', // Important!
        'validity_period' => '[2020-01-01,)',
    ]);

    $response = $this->getJson('/api/v1/articles/search?q=licenciement');

    dump($response->json());

    $response->assertStatus(200);
});
