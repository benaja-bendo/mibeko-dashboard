<?php

use App\Models\LegalDocument;
use App\Models\Institution;
use App\Models\Article;
use App\Models\ArticleVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can list legal documents via api v1', function () {
    $institution = Institution::factory()->create(['nom' => 'Test Institution']);
    LegalDocument::factory()->count(5)->create([
        'institution_id' => $institution->id,
    ]);

    $response = $this->getJson('/api/v1/legal-documents');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'reference',
                    'status',
                    'dates' => ['signature', 'publication'],
                    'institution',
                ]
            ],
            'links',
            'meta'
        ]);
});

it('can show a specific legal document via api v1', function () {
    $document = LegalDocument::factory()->create([
        'titre_officiel' => 'Test Document',
        'statut' => 'vigueur'
    ]);
    
    $article = Article::factory()->create(['document_id' => $document->id]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Version 1 Content'
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.title', 'Test Document')
        ->assertJsonPath('data.status', 'vigueur')
        ->assertJsonStructure([
            'data' => [
                'id',
                'title',
                'articles' => [
                    '*' => ['id', 'number', 'content']
                ]
            ]
        ]);
});
