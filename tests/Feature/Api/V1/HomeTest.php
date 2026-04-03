<?php

use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Disable automatic embedding generation to avoid API calls during tests
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
});

it('can fetch home page data', function () {
    $type = DocumentType::create(['code' => 'CODE', 'nom' => 'Code']);

    LegalDocument::factory()->create([
        'titre_officiel' => 'Code du Travail',
        'type_code' => $type->code,
    ]);

    LegalDocument::factory()->count(2)->create([
        'type_code' => $type->code,
    ]);

    $response = $this->getJson('/api/v1/home');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'popular_codes',
                'recently_added',
                'ai_suggestions',
            ],
        ]);
});
