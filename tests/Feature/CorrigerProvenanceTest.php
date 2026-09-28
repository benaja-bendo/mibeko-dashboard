<?php

use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fichierProvenance(array $entrees): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'provenance_').'.json';
    file_put_contents($chemin, json_encode($entrees, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

function provenanceCode(string $id): array
{
    return ['id' => $id, 'source_url' => 'https://sgg.cg/codes/code.pdf', 'fetched_at' => '2025-10-25T00:00:00+00:00',
        'autorite' => 'SGG ; art. 33 selon la loi n° 22/88, d\'après la consolidation Droit-Afrique'];
}

it('ne touche pas une provenance déjà renseignée sans --remplacer', function () {
    $code = LegalDocument::factory()->create(['metadata' => ['source_url' => 'https://sgg.cg/codes/code.pdf', 'autorite' => 'SGG']]);

    $this->artisan('mibeko:corriger-provenance-documents', [
        '--mapping' => fichierProvenance([provenanceCode($code->id)]),
        '--connection' => 'pgsql',
        '--execute' => true,
    ])->assertSuccessful();

    expect($code->fresh()->metadata['autorite'])->toBe('SGG');
});

it('remplace la provenance du seul document nommé et garde l\'ancienne pour le retour arrière', function () {
    $code = LegalDocument::factory()->create(['metadata' => [
        'source_url' => 'https://sgg.cg/codes/code.pdf', 'autorite' => 'SGG', 'ingestion_mode' => 'web_upload',
    ]]);
    $autre = LegalDocument::factory()->create(['metadata' => ['source_url' => 'https://natlex.ilo.org/6-96.pdf', 'autorite' => 'NATLEX']]);
    $retour = tempnam(sys_get_temp_dir(), 'retour_');

    $this->artisan('mibeko:corriger-provenance-documents', [
        '--mapping' => fichierProvenance([provenanceCode($code->id), ['id' => $autre->id, 'source_url' => 'https://ailleurs.test', 'autorite' => 'Autre']]),
        '--connection' => 'pgsql',
        '--execute' => true,
        '--remplacer' => [$code->id],
        '--revert-file' => $retour,
    ])->assertSuccessful();

    expect($code->fresh()->metadata)
        ->autorite->toContain('Droit-Afrique')
        ->ingestion_mode->toBe('web_upload')
        ->and($autre->fresh()->metadata['autorite'])->toBe('NATLEX')
        ->and(json_decode(file_get_contents($retour), true))->toEqual([
            ['id' => $code->id, 'document_key' => $code->document_key, 'metadata' => [
                'source_url' => 'https://sgg.cg/codes/code.pdf', 'autorite' => 'SGG', 'ingestion_mode' => 'web_upload',
            ]],
        ]);
});

it('refuse un --remplacer qui vise un document absent du fichier', function () {
    $code = LegalDocument::factory()->create();

    $this->artisan('mibeko:corriger-provenance-documents', [
        '--mapping' => fichierProvenance([provenanceCode($code->id)]),
        '--remplacer' => ['01a0e4d5-646b-7111-b6b2-2df03b2912f3'],
    ])->assertFailed();
});
