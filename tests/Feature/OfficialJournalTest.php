<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('lists published official journals in the api', function () {
    OfficialJournal::factory()->create(['is_published' => true, 'title' => 'Journal 1']);
    OfficialJournal::factory()->create(['is_published' => false, 'title' => 'Journal 2']);

    $this->getJson('/api/v1/official-journals')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Journal 1');
});

it('expose le nombre de textes publiés sur chaque journal de la liste', function () {
    $journal = OfficialJournal::factory()->create(['is_published' => true]);
    LegalDocument::factory()->create([
        'official_journal_id' => $journal->id,
        'curation_status' => 'published',
    ]);
    // Un texte non publié ne doit pas compter.
    LegalDocument::factory()->create([
        'official_journal_id' => $journal->id,
        'curation_status' => 'draft',
    ]);

    $this->getJson('/api/v1/official-journals')
        ->assertSuccessful()
        ->assertJsonPath('data.0.legal_documents_count', 1);
});

it('expose le nombre d\'articles de chaque texte sur le détail d\'un journal', function () {
    Storage::fake('local');

    $journal = OfficialJournal::factory()->create(['is_published' => true]);
    $doc = LegalDocument::factory()->create([
        'official_journal_id' => $journal->id,
        'curation_status' => 'published',
    ]);
    Article::factory()->count(3)->create(['document_id' => $doc->id]);

    // La ressource « show » n'est pas enveloppée dans `data` (withoutWrapping).
    $this->getJson("/api/v1/official-journals/{$journal->id}")
        ->assertSuccessful()
        ->assertJsonPath('legal_documents.0.articles_count', 3);
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

it('a retiré les anciennes routes web de gestion des journaux', function () {
    // La gestion vit désormais dans le front éditeur via l'API V1
    // (PATCH /api/v1/official-journals/{id}, PATCH /api/v1/legal-documents/{id}).
    $admin = User::factory()->create();
    $journal = OfficialJournal::factory()->create();

    $this->actingAs($admin)
        ->post('/official-journals', ['title' => 'Nouveau JO'])
        ->assertNotFound();

    $this->actingAs($admin)
        ->post("/official-journals/{$journal->id}/attach", ['legal_document_id' => 'x'])
        ->assertNotFound();
});

it('allows attaching a document to an official journal via the api', function () {
    Permission::findOrCreate('documents.update');
    $adminRole = Role::findOrCreate('admin');
    $adminRole->givePermissionTo('documents.update');

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $journal = OfficialJournal::factory()->create();
    $doc = LegalDocument::factory()->create(['official_journal_id' => null]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/legal-documents/{$doc->id}", [
            'official_journal_id' => $journal->id,
        ])
        ->assertOk();

    $this->assertDatabaseHas('legal_documents', [
        'id' => $doc->id,
        'official_journal_id' => $journal->id,
    ]);
});

it('liste les années de publication des journaux publiés', function () {
    OfficialJournal::factory()->create(['is_published' => true, 'publication_date' => '2026-03-01']);
    OfficialJournal::factory()->create(['is_published' => true, 'publication_date' => '2026-07-15']);
    OfficialJournal::factory()->create(['is_published' => true, 'publication_date' => '1959-06-04']);
    // Non publié : ne doit pas apparaître.
    OfficialJournal::factory()->create(['is_published' => false, 'publication_date' => '2001-01-01']);

    $this->getJson('/api/v1/official-journals/years')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.year', 2026)
        ->assertJsonPath('data.0.total', 2)
        ->assertJsonPath('data.1.year', 1959);
});
