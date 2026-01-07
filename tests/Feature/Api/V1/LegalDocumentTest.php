<?php

use App\Models\LegalDocument;
use App\Models\Institution;
use App\Models\Article;
use App\Models\ArticleVersion;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can filter legal documents by status', function () {
    LegalDocument::factory()->create(['statut' => 'vigueur']);
    LegalDocument::factory()->create(['statut' => 'projet']);

    $response = $this->getJson('/api/v1/legal-documents?filter[statut]=vigueur');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'vigueur');
});

it('can search legal documents by title', function () {
    LegalDocument::factory()->create(['titre_officiel' => 'Unique Title']);
    LegalDocument::factory()->create(['titre_officiel' => 'Other Title']);

    $response = $this->getJson('/api/v1/legal-documents?filter[titre_officiel]=Unique');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Unique Title');
});

it('can list institutions', function () {
    Institution::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/institutions');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('can get document tree hierarchy', function () {
    $document = LegalDocument::factory()->create();
    \App\Models\StructureNode::factory()->count(2)->create([
        'document_id' => $document->id
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/tree");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('can login and get me', function () {
    $user = \App\Models\User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password')
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'test@example.com',
        'password' => 'password',
        'device_name' => 'test-device'
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['token']);

    $token = $response->json('token');

    $meResponse = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->getJson('/api/v1/me');

    $meResponse->assertStatus(200)
        ->assertJsonPath('email', 'test@example.com');
});
