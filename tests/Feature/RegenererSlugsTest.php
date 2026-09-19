<?php

use App\Jobs\PurgeCdnCache;
use App\Models\DocumentSlugAlias;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\Bus;

/**
 * Régénération des slugs depuis la citation (dashboard#156). Le préalable
 * `numero_acte` (dashboard#162) est supposé déjà appliqué : ces tests
 * peuplent directement la colonne, comme le fera la production une fois le
 * Temps 3 de #162 exécuté.
 */
beforeEach(function () {
    foreach (['DEC', 'ARR', 'CONST'] as $code) {
        DocumentType::firstOrCreate(['code' => $code], ['nom' => $code]);
    }
    config(['services.cloudflare.zone_id' => null, 'services.cloudflare.api_token' => null]);
});

function documentCite(string $slug, string $type, string $numero, string $dateSignature, array $extra = []): LegalDocument
{
    return LegalDocument::factory()->create(array_merge([
        'slug' => $slug,
        'type_code' => $type,
        'numero_acte' => $numero,
        'numero_acte_source' => 'titre',
        'date_signature' => $dateSignature,
        'curation_status' => 'published',
    ], $extra));
}

it('ne touche à rien sans --execute', function () {
    $document = documentCite('slug-tronque', 'DEC', '2025-240', '2025-06-20');

    $this->artisan('mibeko:regenerer-slugs', ['--connection' => 'pgsql'])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('slug-tronque');
});

it('régénère le slug et conserve l\'ancien en alias', function () {
    $document = documentCite('slug-tronque', 'DEC', '2025-240', '2025-06-20');
    $revert = tempnam(sys_get_temp_dir(), 'revert_').'.json';

    $this->artisan('mibeko:regenerer-slugs', [
        '--connection' => 'pgsql',
        '--execute' => true,
        '--revert-file' => $revert,
    ])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('decret-n-2025-240-du-20-juin-2025')
        ->and(DocumentSlugAlias::where('slug', 'slug-tronque')->value('legal_document_id'))->toBe($document->id);

    $inverse = json_decode((string) file_get_contents($revert), true);
    expect($inverse[0]['slug'])->toBe('slug-tronque');
});

it('laisse intact un slug déjà conforme à sa citation', function () {
    $document = documentCite('decret-n-2025-240-du-20-juin-2025', 'DEC', '2025-240', '2025-06-20');

    $this->artisan('mibeko:regenerer-slugs', ['--connection' => 'pgsql', '--execute' => true])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('decret-n-2025-240-du-20-juin-2025')
        ->and(DocumentSlugAlias::count())->toBe(0);
});

it('laisse intacts les documents hors périmètre (type sans mot de citation)', function () {
    DocumentType::firstOrCreate(['code' => 'TEXTE'], ['nom' => 'TEXTE']);
    $document = documentCite('avis-346-office-des-changes', 'TEXTE', '346', '2020-01-01');

    $this->artisan('mibeko:regenerer-slugs', ['--connection' => 'pgsql', '--execute' => true])->assertSuccessful();

    expect($document->fresh()->slug)->toBe('avis-346-office-des-changes');
});

it('refuse de tourner en entier quand une citation est partagée — rien n\'est écrit', function () {
    $premier = documentCite('slug-a-corriger', 'ARR', '3830', '2025-09-08');
    $second = documentCite('arrete-n-3830', 'ARR', '3830', '2025-09-08');

    $this->artisan('mibeko:regenerer-slugs', ['--connection' => 'pgsql', '--execute' => true])->assertFailed();

    expect($premier->fresh()->slug)->toBe('slug-a-corriger')
        ->and($second->fresh()->slug)->toBe('arrete-n-3830')
        ->and(DocumentSlugAlias::count())->toBe(0);
});

it('écarte un candidat qui coïnciderait avec l\'ALIAS existant d\'un AUTRE document', function () {
    // A n'a PAS de numero_acte : hors du périmètre de cette commande, son
    // slug ne bouge donc pas au passage de la régénération elle-même. Il a
    // autrefois porté ce slug ; il devient son alias quand A en change
    // (hook de dashboard#155) — exactement ce que B va ensuite convoiter.
    $a = LegalDocument::factory()->create(['slug' => 'arrete-n-100-du-5-mai-2020', 'curation_status' => 'published']);
    $a->update(['slug' => 'nouveau-slug-de-a']);

    // La citation de B dérive EXACTEMENT vers le slug devenu l'alias de A.
    $b = documentCite('slug-b-quelconque', 'ARR', '100', '2020-05-05');

    $this->artisan('mibeko:regenerer-slugs', ['--connection' => 'pgsql', '--execute' => true])->assertSuccessful();

    // B n'est PAS régénéré : le candidat est écarté, pas forcé en collision
    // silencieuse avec l'alias de A.
    expect($b->fresh()->slug)->toBe('slug-b-quelconque')
        ->and($a->fresh()->slug)->toBe('nouveau-slug-de-a')
        ->and(DocumentSlugAlias::where('slug', 'arrete-n-100-du-5-mai-2020')->value('legal_document_id'))->toBe($a->id);
});

it('purge le CDN quand des slugs sont réellement régénérés', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);
    documentCite('slug-tronque', 'DEC', '2025-240', '2025-06-20');

    Bus::fake();

    $this->artisan('mibeko:regenerer-slugs', ['--connection' => 'pgsql', '--execute' => true])->assertSuccessful();

    Bus::assertDispatched(PurgeCdnCache::class, 1);
});

it('ne purge pas le CDN quand rien n\'a changé', function () {
    config(['services.cloudflare.zone_id' => 'zone-test', 'services.cloudflare.api_token' => 'token-test']);
    documentCite('decret-n-2025-240-du-20-juin-2025', 'DEC', '2025-240', '2025-06-20');

    Bus::fake();

    $this->artisan('mibeko:regenerer-slugs', ['--connection' => 'pgsql', '--execute' => true])->assertSuccessful();

    Bus::assertNotDispatched(PurgeCdnCache::class);
});

it('refuse d\'écrire sur la connexion de diagnostic en lecture seule', function () {
    documentCite('slug-tronque', 'DEC', '2025-240', '2025-06-20');

    $this->artisan('mibeko:regenerer-slugs', ['--connection' => 'pgsql_prod_ro', '--execute' => true])->assertFailed();
});
