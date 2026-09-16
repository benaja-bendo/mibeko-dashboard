<?php

use App\Models\Article;
use App\Models\DocumentControleRun;
use App\Models\DocumentRelecturePreuve;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * dashboard#142 — endpoints de relecture dirigée : le serveur calcule (le
 * front ne recalcule rien) et ne fait jamais confiance au client sur ce qui
 * était exigé.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('editor');
    Permission::findOrCreate('documents.update');
    Role::findByName('editor')->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('computes and returns the requirements for a document with an echec run', function () {
    $document = LegalDocument::factory()->create();
    $a1 = Article::factory()->create(['document_id' => $document->id, 'ordre_affichage' => 1, 'numero_article' => '1']);
    $a2 = Article::factory()->create(['document_id' => $document->id, 'ordre_affichage' => 2, 'numero_article' => '2']);
    $run = DocumentControleRun::factory()->create(['document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC]);

    $response = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/relecture")
        ->assertOk();

    $response->assertJsonPath('data.document_controle_run_id', $run->id)
        ->assertJsonPath('data.preuve_existante', false);

    $pointsIds = collect($response->json('data.points_obligatoires'))->pluck('id');
    expect($pointsIds)->toContain($a1->id, $a2->id);
});

it('reports preuve_existante=true once a matching proof is recorded', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id]);
    $run = DocumentControleRun::factory()->create(['document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC]);
    DocumentRelecturePreuve::factory()->create(['document_id' => $document->id, 'document_controle_run_id' => $run->id]);

    $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/relecture")
        ->assertOk()
        ->assertJsonPath('data.preuve_existante', true);
});

it('rejects recording a proof that does not cover every required point', function () {
    $document = LegalDocument::factory()->create();
    $a1 = Article::factory()->create(['document_id' => $document->id]);
    DocumentControleRun::factory()->create(['document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC]);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/relecture", [
            'points_vus' => [],
            'sondage_confirmes' => [],
        ])
        ->assertStatus(422);

    expect(DocumentRelecturePreuve::where('document_id', $document->id)->count())->toBe(0);
});

it('records a proof when the client submits exactly what the server computed', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id, 'ordre_affichage' => 1, 'numero_article' => '1']);
    $run = DocumentControleRun::factory()->create(['document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC]);

    $requis = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/relecture")
        ->json('data');

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/relecture", [
            'points_vus' => collect($requis['points_obligatoires'])->pluck('id')->all(),
            'sondage_confirmes' => collect($requis['sondage_articles'])->pluck('id')->all(),
        ])
        ->assertStatus(201);

    $preuve = DocumentRelecturePreuve::where('document_id', $document->id)->first();
    expect($preuve)->not->toBeNull()
        ->and($preuve->document_controle_run_id)->toBe($run->id)
        ->and($preuve->actor_id)->toBe($this->editor->id);
});

it('rejects recording a proof when the document has no controle run at all', function () {
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id]);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/relecture", [
            'points_vus' => [],
            'sondage_confirmes' => [],
        ])
        ->assertStatus(422);
});

it('never trusts a client-supplied extra id as satisfying the requirement', function () {
    // Le client soumet un ID d'article qui n'appartient même pas au document —
    // le serveur doit toujours recalculer, jamais accepter tel quel.
    $document = LegalDocument::factory()->create();
    Article::factory()->create(['document_id' => $document->id]);
    DocumentControleRun::factory()->create(['document_id' => $document->id, 'resultat' => DocumentControleRun::RESULTAT_ECHEC]);
    $articleEtranger = Article::factory()->create();

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/relecture", [
            'points_vus' => [$articleEtranger->id],
            'sondage_confirmes' => [$articleEtranger->id],
        ])
        ->assertStatus(422);
});
