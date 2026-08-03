<?php

use App\Models\LegalDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fichierSlugs(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'slugs_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

it('ne touche à rien sans --execute', function () {
    $document = LegalDocument::factory()->create(['slug' => 'ancien-slug']);

    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => fichierSlugs([['id' => $document->id, 'slug' => 'nouveau-slug-complet']]),
        '--connection' => 'pgsql',
    ])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('ancien-slug');
});

it('corrige le slug avec --execute, y compris sur un document publié', function () {
    $document = LegalDocument::factory()->create([
        'slug' => 'slug-tronque',
        'curation_status' => 'published',
    ]);
    $revert = tempnam(sys_get_temp_dir(), 'revert_').'.json';

    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => fichierSlugs([['id' => $document->id, 'slug' => 'slug-complet-non-tronque']]),
        '--connection' => 'pgsql',
        '--execute' => true,
        '--revert-file' => $revert,
    ])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('slug-complet-non-tronque');
    expect(json_decode((string) file_get_contents($revert), true)[0]['slug'])->toBe('slug-tronque');
});

it('refuse un slug déjà pris par un autre document', function () {
    LegalDocument::factory()->create(['slug' => 'deja-pris']);
    $document = LegalDocument::factory()->create(['slug' => 'a-corriger']);

    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => fichierSlugs([['id' => $document->id, 'slug' => 'deja-pris']]),
        '--connection' => 'pgsql',
        '--execute' => true,
    ])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('a-corriger');
});

it('refuse --execute sur la connexion de diagnostic en lecture seule', function () {
    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => fichierSlugs([['id' => 'peu-importe', 'slug' => 'x']]),
        '--connection' => 'pgsql_prod_ro',
        '--execute' => true,
    ])->assertFailed();
});
