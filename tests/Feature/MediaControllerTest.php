<?php

use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('can list available files from s3', function () {
    $user = User::factory()->create();

    Storage::fake('s3');
    Storage::disk('s3')->put('sources/test1.pdf', 'dummy content');
    Storage::disk('s3')->put('sources/test2.pdf', 'dummy content');

    $response = $this->actingAs($user)->getJson('/api/media/files');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'files');
});

it('can attach a file to a document', function () {
    $user = User::factory()->create();

    Storage::fake('s3');
    Storage::disk('s3')->put('sources/test.pdf', 'dummy content');

    $document = LegalDocument::factory()->create();

    $response = $this->actingAs($user)->post("/curation/{$document->id}/attach-media", [
        'file_path' => 'sources/test.pdf',
    ]);

    $response->assertStatus(302);

    $this->assertDatabaseHas('media_files', [
        'document_id' => $document->id,
        'file_path' => 'sources/test.pdf',
    ]);
});

it('returns 404 if file to attach does not exist', function () {
    $user = User::factory()->create();

    Storage::fake('s3');

    $document = LegalDocument::factory()->create();

    $response = $this->actingAs($user)->postJson("/curation/{$document->id}/attach-media", [
        'file_path' => 'sources/missing.pdf',
    ]);

    $response->assertStatus(404);
});
