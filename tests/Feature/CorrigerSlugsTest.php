<?php

use App\Models\DocumentSlugAlias;
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

it('conserve l\'ancien slug en alias résolvable par l\'API', function () {
    $document = LegalDocument::factory()->create([
        'slug' => 'slug-tronque',
        'curation_status' => 'published',
    ]);
    $document->articles()->create(['numero_article' => '1', 'ordre_affichage' => 1]);

    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => fichierSlugs([['id' => $document->id, 'slug' => 'slug-complet']]),
        '--connection' => 'pgsql',
        '--execute' => true,
        '--revert-file' => tempnam(sys_get_temp_dir(), 'revert_').'.json',
    ])->assertSuccessful();

    expect(DocumentSlugAlias::where('slug', 'slug-tronque')->value('legal_document_id'))->toBe($document->id);

    $this->getJson('/api/v1/legal-documents/slug/slug-tronque')
        ->assertStatus(200)
        ->assertJsonPath('data.canonical_slug', 'slug-complet');
});

it('refuse un slug qui est l\'ancienne URL d\'un autre document', function () {
    $autre = LegalDocument::factory()->create(['slug' => 'ancienne-url-d-autrui']);
    $autre->update(['slug' => 'autre-nouveau']);
    $document = LegalDocument::factory()->create(['slug' => 'a-corriger']);

    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => fichierSlugs([['id' => $document->id, 'slug' => 'ancienne-url-d-autrui']]),
        '--connection' => 'pgsql',
        '--execute' => true,
        '--revert-file' => tempnam(sys_get_temp_dir(), 'revert_').'.json',
    ])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('a-corriger');
    expect(DocumentSlugAlias::where('slug', 'ancienne-url-d-autrui')->value('legal_document_id'))->toBe($autre->id);
});

it('rejoue le fichier de retour arrière sans laisser d\'alias orphelin', function () {
    $document = LegalDocument::factory()->create(['slug' => 'slug-a']);
    $revert = tempnam(sys_get_temp_dir(), 'revert_').'.json';

    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => fichierSlugs([['id' => $document->id, 'slug' => 'slug-b']]),
        '--connection' => 'pgsql',
        '--execute' => true,
        '--revert-file' => $revert,
    ])->assertSuccessful();

    // Retour arrière : slug-a redevient canonique (son alias tombe), slug-b devient alias.
    $this->artisan('mibeko:corriger-slugs', [
        '--mapping' => $revert,
        '--connection' => 'pgsql',
        '--execute' => true,
        '--revert-file' => tempnam(sys_get_temp_dir(), 'revert_').'.json',
    ])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('slug-a');
    expect(DocumentSlugAlias::pluck('slug')->all())->toBe(['slug-b']);
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
