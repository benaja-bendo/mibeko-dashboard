<?php

use App\Events\DocumentExtractionUpdated;
use App\Models\Article;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->type = DocumentType::create([
        'code' => 'LOI',
        'nom' => 'Loi',
        'niveau_hierarchique' => 1,
    ]);

    $this->institution = Institution::create([
        'nom' => 'Assemblée Nationale',
        'sigle' => 'AN',
    ]);
});

it('broadcasts DocumentExtractionUpdated on the correct public channel', function () {
    Event::fake([DocumentExtractionUpdated::class]);

    $document = LegalDocument::create([
        'titre_officiel' => 'Loi de Test Broadcast',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'draft',
        'extraction_status' => 'processing',
    ]);

    broadcast(new DocumentExtractionUpdated($document));

    Event::assertDispatched(DocumentExtractionUpdated::class, function ($event) use ($document) {
        return $event->document->id === $document->id;
    });
});

it('uses a public channel named curation.documents', function () {
    $document = LegalDocument::create([
        'titre_officiel' => 'Loi Channel Test',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'draft',
        'extraction_status' => 'completed',
    ]);

    $event = new DocumentExtractionUpdated($document);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(Channel::class);
    expect($channels[0]->name)->toBe('curation.documents');
});

it('includes the correct data in the broadcast payload', function () {
    $document = LegalDocument::create([
        'titre_officiel' => 'Loi Payload Test',
        'type_code' => $this->type->code,
        'institution_id' => $this->institution->id,
        'curation_status' => 'draft',
        'extraction_status' => 'processing',
    ]);

    $event = new DocumentExtractionUpdated($document);
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKeys(['id', 'extraction_status', 'progression', 'articles_count']);
    expect($payload['id'])->toBe($document->id);
    expect($payload['extraction_status'])->toBe('processing');
    expect($payload['articles_count'])->toBe(0);
    expect($payload['progression'])->toBe(0);
});
