<?php

use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('can proxy a pdf file', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('test.pdf', 'dummy content');

    $document = LegalDocument::factory()->create();

    $document->mediaFiles()->create([
        'file_path' => 'test.pdf',
        'object_key' => 'test.pdf',
        'file_category' => 'SOURCE_PDF',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $response = $this->get("/api/v1/legal-documents/{$document->id}/pdf");

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

it('returns 404 if pdf does not exist in storage', function () {
    Storage::fake('s3');

    $document = LegalDocument::factory()->create();

    $document->mediaFiles()->create([
        'file_path' => 'missing.pdf',
        'object_key' => 'missing.pdf',
        'file_category' => 'SOURCE_PDF',
        'original_filename' => 'missing.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $response = $this->get("/api/v1/legal-documents/{$document->id}/pdf");

    $response->assertStatus(404);
});

it('returns 404 if document has no pdf', function () {
    $document = LegalDocument::factory()->create();

    $response = $this->get("/api/v1/legal-documents/{$document->id}/pdf");

    $response->assertStatus(404);
});
