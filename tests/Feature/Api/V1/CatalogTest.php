<?php

namespace Tests\Feature\Api\V1;

use App\Models\DocumentType;
use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_returns_correct_structure()
    {
        // Arrange
        $type = DocumentType::create(['code' => 'CODE', 'nom' => 'Code']);
        LegalDocument::create([
            'id' => '00000000-0000-0000-0000-000000000001',
            'titre_officiel' => 'Code de la Famille',
            'type_id' => $type->id,
            'type_code' => 'CODE', // Required by DB constraint
            'date_promulgation' => now(),
        ]);

        // Act
        $response = $this->getJson('/api/v1/catalog');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Catalogue récupéré avec succès',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'global_update_required',
                    'last_essential_sync',
                    'resources' => [
                        '*' => [
                            'id',
                            'title',
                            'type',
                            'version_hash',
                            'last_updated',
                            'download_size_kb',
                        ]
                    ]
                ]
            ]);
    }
}
