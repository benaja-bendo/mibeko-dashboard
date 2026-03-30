<?php

use App\Models\Article;
use App\Models\LegalDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can export a dossier to PDF', function () {
    Pdf::shouldReceive('loadView')
        ->once()
        ->andReturnSelf();
        
    Pdf::shouldReceive('setPaper')
        ->once()
        ->andReturnSelf();
        
    Pdf::shouldReceive('setOption')
        ->twice()
        ->andReturnSelf();
        
    Pdf::shouldReceive('output')
        ->once()
        ->andReturn('fake-pdf-content');

    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create([
        'document_id' => $document->id,
    ]);
    
    $response = $this->postJson('/api/v1/dossiers/export-pdf', [
        'title' => 'Mon Dossier',
        'description' => 'Description du dossier',
        'items' => [
            [
                'type' => 'article',
                'id' => $article->id,
                'note' => 'Note importante',
            ],
        ],
    ]);

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

it('validates dossier export request', function () {
    $response = $this->postJson('/api/v1/dossiers/export-pdf', [
        // missing title
        'items' => [],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['title', 'items']);
});
