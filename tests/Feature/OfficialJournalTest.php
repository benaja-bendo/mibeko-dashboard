<?php

use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lists published official journals in the api', function () {
    OfficialJournal::factory()->create(['is_published' => true, 'title' => 'Journal 1']);
    OfficialJournal::factory()->create(['is_published' => false, 'title' => 'Journal 2']);

    $this->getJson('/api/v1/official-journals')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Journal 1');
});

it('shows a specific official journal with its documents', function () {
    Storage::fake('local');

    $journal = OfficialJournal::factory()->create(['is_published' => true]);
    $doc = LegalDocument::factory()->create([
        'official_journal_id' => $journal->id,
        'curation_status' => 'published',
    ]);

    $response = $this->getJson("/api/v1/official-journals/{$journal->id}");

    $response->assertSuccessful();

    $data = $response->json();

    expect($data['data']['id'] ?? $data['id'])->toBe($journal->id);
    expect($data['data']['legal_documents'][0]['id'] ?? $data['legal_documents'][0]['id'])->toBe($doc->id);
});

it('allows admin to create an official journal via web', function () {
    Storage::fake('local');
    $admin = User::factory()->create();
    // Assuming the user has admin role or permissions setup
    // Let's bypass role check by just acting as a user if the route only requires auth
    // Wait, the routes are protected by `auth` middleware.

    $file = UploadedFile::fake()->create('journal.pdf', 100, 'application/pdf');

    $this->actingAs($admin)
        ->post('/official-journals', [
            'title' => 'Nouveau JO',
            'publication_date' => '2026-04-10',
            'is_published' => true,
            'file' => $file,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('official_journals', [
        'title' => 'Nouveau JO',
        'is_published' => 1,
    ]);
});

it('allows attaching a document to an official journal', function () {
    $admin = User::factory()->create();
    $journal = OfficialJournal::factory()->create();
    $doc = LegalDocument::factory()->create(['official_journal_id' => null]);

    $this->actingAs($admin)
        ->post("/official-journals/{$journal->id}/attach", [
            'legal_document_id' => $doc->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('legal_documents', [
        'id' => $doc->id,
        'official_journal_id' => $journal->id,
    ]);
});
