<?php

use App\Models\User;
use App\Models\LegalDocument;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    // Ensure we have types and institutions
    $this->type = DocumentType::create([
        'code' => 'LOI',
        'nom' => 'Loi',
        'niveau_hierarchique' => 1
    ]);

    $this->institution = Institution::create([
        'nom' => 'Assemblée Nationale',
        'sigle' => 'AN'
    ]);
});

it('displays the curation index page', function () {
    $response = $this->actingAs($this->user)
        ->get('/curation');

    $response->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('curation/index')
            ->has('documents.data')
            ->has('document_types')
            ->has('institutions')
        );
});

it('filters documents by search query', function () {
    LegalDocument::create([
        'titre_officiel' => 'Loi Spécifique Alpha',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'draft'
    ]);

    LegalDocument::create([
        'titre_officiel' => 'Décret Beta',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'draft'
    ]);

    $response = $this->actingAs($this->user)
        ->get('/curation?search=Alpha');

    $response->assertInertia(fn ($page) => $page
        ->has('documents.data', 1)
        ->where('documents.data.0.title', 'Loi Spécifique Alpha')
    );
});

it('creates a new legal document', function () {
    $data = [
        'titre_officiel' => 'Nouvelle Loi de Test',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'review',
        'date_publication' => '2025-01-01',
    ];

    $response = $this->actingAs($this->user)
        ->post('/curation', $data);

    $document = LegalDocument::where('titre_officiel', 'Nouvelle Loi de Test')->first();
    expect($document)->not->toBeNull();

    $response->assertRedirect("/curation/{$document->id}");
});

it('deletes a legal document', function () {
    $document = LegalDocument::create([
        'titre_officiel' => 'Document à supprimer',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'draft'
    ]);

    $response = $this->actingAs($this->user)
        ->delete("/curation/{$document->id}");

    $response->assertStatus(302);
    expect(LegalDocument::find($document->id))->toBeNull();
});

it('displays the curation workstation with metadata', function () {
    $document = LegalDocument::create([
        'titre_officiel' => 'Loi de test',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'draft',
        'date_signature' => '2025-06-01',
        'date_publication' => '2025-06-15',
    ]);

    $response = $this->actingAs($this->user)
        ->get("/curation/{$document->id}");

    $response->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('curation/workstation')
            ->where('document.date_signature', '2025-06-01')
            ->where('document.date_publication', '2025-06-15')
        );
});

it('updates document metadata', function () {
    $document = LegalDocument::create([
        'titre_officiel' => 'Ancien Titre',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'draft'
    ]);

    $response = $this->actingAs($this->user)
        ->patch("/curation/{$document->id}", [
            'title' => 'Nouveau Titre',
            'status' => 'review',
            'date_signature' => '2025-12-01',
        ]);

    $document->refresh();
    expect($document->titre_officiel)->toBe('Nouveau Titre');
    expect($document->curation_status)->toBe('review');
    expect($document->date_signature->format('Y-m-d'))->toBe('2025-12-01');
    $response->assertStatus(302);
});
