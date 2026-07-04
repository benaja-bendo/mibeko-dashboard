<?php

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Le quota dédié est couvert par RateLimitingTest ; ici on teste le
    // comportement métier du signalement public.
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('enregistre un signalement anonyme en source report / sévérité info', function () {
    $document = LegalDocument::factory()->create();

    $this->postJson('/api/v1/reports', [
        'document_id' => $document->id,
        'type_probleme' => 'erreur',
        'description' => 'L\'article 12 est illisible.',
        // Tentative d'escalade : un appelant anonyme ne doit jamais pouvoir
        // choisir la source ni la sévérité de son signalement.
        'source' => CurationFlag::SOURCE_HUMAN,
        'severity' => CurationFlag::SEVERITY_BLOCKING,
    ])->assertCreated();

    $flag = CurationFlag::query()->sole();
    expect($flag->source)->toBe(CurationFlag::SOURCE_REPORT)
        ->and($flag->severity)->toBe(CurationFlag::SEVERITY_INFO)
        ->and($flag->resolved)->toBeFalse()
        ->and($flag->document_id)->toBe($document->id);
});

it('ne laisse pas un signalement anonyme bloquer la publication', function () {
    Embeddings::fake();

    Permission::findOrCreate('documents.update');
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);
    Article::factory()->create(['document_id' => $document->id]);

    $this->postJson('/api/v1/reports', [
        'document_id' => $document->id,
        'type_probleme' => 'erreur',
        'description' => 'Signalement anonyme non vérifié.',
    ])->assertCreated();

    // Le garde-fou de publication ne compte que les anomalies bloquantes :
    // un signalement public (info) non résolu ne doit pas déclencher le 422.
    $this->actingAs($editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk();

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('exige une cible (document ou article)', function () {
    $this->postJson('/api/v1/reports', [
        'type_probleme' => 'erreur',
        'description' => 'Signalement sans cible.',
    ])->assertStatus(422);

    expect(CurationFlag::query()->count())->toBe(0);
});

it('refuse une description démesurée', function () {
    $document = LegalDocument::factory()->create();

    $this->postJson('/api/v1/reports', [
        'document_id' => $document->id,
        'type_probleme' => 'erreur',
        'description' => str_repeat('a', 5001),
    ])->assertStatus(422);
});
