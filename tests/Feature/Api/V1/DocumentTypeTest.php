<?php

use App\Models\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can list document types', function () {
    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 1]);
    DocumentType::create(['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 2]);
    
    $response = $this->getJson('/api/v1/document-types');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

it('can filter document types by code', function () {
    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi', 'niveau_hierarchique' => 1]);
    DocumentType::create(['code' => 'DEC', 'nom' => 'Décret', 'niveau_hierarchique' => 2]);
    
    $response = $this->getJson('/api/v1/document-types?filter[code]=LOI');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'LOI');
});
