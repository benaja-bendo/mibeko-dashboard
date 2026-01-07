<?php

use App\Models\User;
use App\Models\LegalDocument;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('unauthenticated users cannot access protected routes', function () {
    $response = $this->getJson('/api/v1/me');
    $response->assertStatus(401);
});

test('can fetch paginated legal documents', function () {
    LegalDocument::factory()->count(25)->create();

    $response = $this->getJson('/api/v1/legal-documents?page=2');

    $response->assertStatus(200)
        ->assertJsonStructure(['data', 'pagination'])
        ->assertJsonPath('pagination.current_page', 2)
        ->assertJsonCount(5, 'data'); // 25 total, 20 per page, so page 2 has 5
});

test('returns 404 for non-existent legal document', function () {
    $uuid = (string) Str::uuid();
    $response = $this->getJson("/api/v1/legal-documents/{$uuid}");

    $response->assertStatus(404);
});

test('can combine filters', function () {
    LegalDocument::factory()->create(['titre_officiel' => 'Alpha', 'statut' => 'vigueur']);
    LegalDocument::factory()->create(['titre_officiel' => 'Beta', 'statut' => 'vigueur']);
    LegalDocument::factory()->create(['titre_officiel' => 'Alpha', 'statut' => 'projet']);

    // Filter by Title 'Alpha' AND Status 'vigueur'
    $url = '/api/v1/legal-documents?filter[titre_officiel]=Alpha&filter[statut]=vigueur';
    $response = $this->getJson($url);

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Alpha')
        ->assertJsonPath('data.0.status', 'vigueur');
});

test('returns validation error for login with invalid credentials', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password',
        'device_name' => 'test-device',
    ]);

    // Laravel Fortify/Sanctum often returns 422 for invalid credentials (validation error)
    // or 401 depending on config. Based on logs, it returns 422.
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

test('returns validation error for login with missing fields', function () {
    $response = $this->postJson('/api/v1/login', [
        'email' => 'test@example.com',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});
