<?php

use Illuminate\Support\Facades\Http;

function fichierStatuts(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'statuts_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

it("n'émet aucun appel réseau sans --execute", function () {
    Http::fake();

    $this->artisan('mibeko:corriger-statut-document', [
        '--mapping' => fichierStatuts([['id' => 'abc', 'document' => 'Test', 'statut' => 'abroge']]),
    ])->assertSuccessful();

    Http::assertNothingSent();
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:corriger-statut-document', [
        '--mapping' => fichierStatuts([['id' => 'abc', 'document' => 'Test', 'statut' => 'abroge']]),
        '--execute' => true,
    ])->assertFailed();
});

it('appelle PATCH /legal-documents/{id} avec le nouveau statut', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:corriger-statut-document', [
        '--mapping' => fichierStatuts([
            ['id' => 'aaa', 'document' => 'Constitution X', 'statut' => 'abroge'],
            ['id' => 'bbb', 'document' => 'Constitution Y', 'statut' => 'vigueur'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/legal-documents/aaa')
        && $request['statut'] === 'abroge');
    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/legal-documents/bbb')
        && $request['statut'] === 'vigueur');

    putenv('MIBEKO_API_TOKEN');
});

it('ignore une entrée sans id ni statut et le signale', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:corriger-statut-document', [
        '--mapping' => fichierStatuts([
            ['id' => '', 'document' => 'Sans id', 'statut' => 'abroge'],
            ['id' => 'valide', 'document' => 'X', 'statut' => 'abroge'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(1);

    putenv('MIBEKO_API_TOKEN');
});
