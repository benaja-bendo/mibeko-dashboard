<?php

use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('simule sans rien ecrire par defaut', function () {
    $vide = LegalDocument::factory()->create(['extraction_status' => 'completed']);

    $this->artisan('mibeko:corriger-extraction-vide')->assertSuccessful();

    expect($vide->fresh()->extraction_status)->toBe('completed');
});

it('corrige uniquement les documents completed sans structure ni article', function () {
    $vide = LegalDocument::factory()->create(['extraction_status' => 'completed']);

    $avecArticle = LegalDocument::factory()->create(['extraction_status' => 'completed']);
    Article::factory()->create(['document_id' => $avecArticle->id]);

    $avecNode = LegalDocument::factory()->create(['extraction_status' => 'completed']);
    StructureNode::factory()->create(['document_id' => $avecNode->id]);

    $pasCompleted = LegalDocument::factory()->create(['extraction_status' => 'processing']);

    $this->artisan('mibeko:corriger-extraction-vide', ['--execute' => true])->assertSuccessful();

    expect($vide->fresh()->extraction_status)->toBe('failed')
        ->and($avecArticle->fresh()->extraction_status)->toBe('completed')
        ->and($avecNode->fresh()->extraction_status)->toBe('completed')
        ->and($pasCompleted->fresh()->extraction_status)->toBe('processing');
});

it('ecrit un fichier de retour arriere restaurable avant toute ecriture', function () {
    $vide = LegalDocument::factory()->create(['extraction_status' => 'completed']);
    $fichier = tempnam(sys_get_temp_dir(), 'retour_').'.json';

    $this->artisan('mibeko:corriger-extraction-vide', [
        '--execute' => true,
        '--revert-file' => $fichier,
    ])->assertSuccessful();

    $retour = json_decode((string) file_get_contents($fichier), true);
    expect($retour)->toBe([['id' => $vide->id, 'extraction_status' => 'completed']]);
});

it('refuse --execute sur une connexion en lecture seule', function () {
    $this->artisan('mibeko:corriger-extraction-vide', [
        '--execute' => true,
        '--connection' => 'pgsql_prod_ro',
    ])->assertFailed();
});

it('n\'ecrit rien quand aucun document ne correspond', function () {
    LegalDocument::factory()->create(['extraction_status' => 'processing']);

    $this->artisan('mibeko:corriger-extraction-vide', ['--execute' => true])->assertSuccessful();
});
