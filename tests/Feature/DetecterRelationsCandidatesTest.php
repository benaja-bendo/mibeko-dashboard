<?php

use App\Models\LegalDocument;
use Illuminate\Support\Facades\Http;

it("n'émet aucun appel réseau sans --execute", function () {
    LegalDocument::factory()->count(3)->create(['curation_status' => LegalDocument::STATUS_PUBLISHED]);
    Http::fake();

    $this->artisan('mibeko:detecter-relations-candidates', [
        '--connection' => 'pgsql',
        '--statut' => LegalDocument::STATUS_PUBLISHED,
    ])->assertSuccessful();

    Http::assertNothingSent();
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_PUBLISHED]);
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:detecter-relations-candidates', [
        '--connection' => 'pgsql',
        '--statut' => LegalDocument::STATUS_PUBLISHED,
        '--execute' => true,
    ])->assertFailed();

    Http::assertNothingSent();
});

it('appelle detect-relations pour chaque document publié et agrège le résultat', function () {
    $documents = LegalDocument::factory()->count(3)->create(['curation_status' => LegalDocument::STATUS_PUBLISHED]);
    LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_DRAFT]);

    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response([
        'success' => true,
        'data' => ['candidats' => [['relation_type' => 'ABROGE'], ['relation_type' => 'MODIFIE']], 'ambigus' => 1],
    ], 200));

    $chemin = tempnam(sys_get_temp_dir(), 'rapport_').'.json';

    $this->artisan('mibeko:detecter-relations-candidates', [
        '--connection' => 'pgsql',
        '--statut' => LegalDocument::STATUS_PUBLISHED,
        '--rapport' => $chemin,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/detect-relations')
        && $documents->pluck('id')->contains(fn ($id) => str_contains($request->url(), (string) $id)));

    $rapport = json_decode((string) file_get_contents($chemin), true);
    expect($rapport)->toHaveCount(3);
    expect($rapport[0]['candidats'])->toBe(2);
    expect($rapport[0]['ambigus'])->toBe(1);

    putenv('MIBEKO_API_TOKEN');
});

it('respecte --limit et --document-id', function () {
    $documents = LegalDocument::factory()->count(5)->create(['curation_status' => LegalDocument::STATUS_PUBLISHED]);

    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true, 'data' => ['candidats' => [], 'ambigus' => 0]], 200));

    $this->artisan('mibeko:detecter-relations-candidates', [
        '--connection' => 'pgsql',
        '--statut' => LegalDocument::STATUS_PUBLISHED,
        '--limit' => 2,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);

    $this->artisan('mibeko:detecter-relations-candidates', [
        '--connection' => 'pgsql',
        '--statut' => '',
        '--document-id' => $documents->first()->id,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(3);

    putenv('MIBEKO_API_TOKEN');
});

it('continue après un échec et le signale sans arrêter le lot', function () {
    LegalDocument::factory()->count(2)->create(['curation_status' => LegalDocument::STATUS_PUBLISHED]);

    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fakeSequence()
        ->push(['message' => 'introuvable'], 404)
        ->push(['success' => true, 'data' => ['candidats' => [], 'ambigus' => 0]], 200);

    $this->artisan('mibeko:detecter-relations-candidates', [
        '--connection' => 'pgsql',
        '--statut' => LegalDocument::STATUS_PUBLISHED,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);

    putenv('MIBEKO_API_TOKEN');
});
