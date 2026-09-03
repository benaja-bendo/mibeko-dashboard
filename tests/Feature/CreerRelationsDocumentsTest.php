<?php

use Illuminate\Support\Facades\Http;

function fichierRelations(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'relations_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

it("n'émet aucun appel réseau sans --execute", function () {
    Http::fake();

    $this->artisan('mibeko:creer-relations-documents', [
        '--mapping' => fichierRelations([[
            'source_doc_id' => 'aaa', 'target_doc_id' => 'bbb',
            'relation_type' => 'ABROGE', 'document' => 'Test',
        ]]),
    ])->assertSuccessful();

    Http::assertNothingSent();
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:creer-relations-documents', [
        '--mapping' => fichierRelations([[
            'source_doc_id' => 'aaa', 'target_doc_id' => 'bbb',
            'relation_type' => 'ABROGE', 'document' => 'Test',
        ]]),
        '--execute' => true,
    ])->assertFailed();
});

it('appelle POST /document-relations avec les bons champs', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 201));

    $this->artisan('mibeko:creer-relations-documents', [
        '--mapping' => fichierRelations([[
            'source_doc_id' => 'aaa',
            'target_doc_id' => 'bbb',
            'relation_type' => 'ABROGE',
            'effective_date' => '2015-10-25',
            'commentaire' => 'Test',
            'document' => 'X → Y',
        ]]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/document-relations')
        && $request['source_doc_id'] === 'aaa'
        && $request['target_doc_id'] === 'bbb'
        && $request['relation_type'] === 'ABROGE'
        && $request['effective_date'] === '2015-10-25');

    putenv('MIBEKO_API_TOKEN');
});

it('ignore une entrée sans source, cible ou type et le signale', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 201));

    $this->artisan('mibeko:creer-relations-documents', [
        '--mapping' => fichierRelations([
            ['source_doc_id' => 'aaa', 'target_doc_id' => null, 'relation_type' => 'ABROGE', 'document' => 'Incomplète'],
            ['source_doc_id' => 'ccc', 'target_doc_id' => 'ddd', 'relation_type' => 'ABROGE', 'document' => 'Valide'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(1);

    putenv('MIBEKO_API_TOKEN');
});
