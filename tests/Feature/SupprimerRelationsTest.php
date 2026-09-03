<?php

use Illuminate\Support\Facades\Http;

function fichierASupprimer(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'suppr_relations_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

it("n'émet aucun appel réseau sans --execute", function () {
    Http::fake();

    $this->artisan('mibeko:supprimer-relations', [
        '--liste' => fichierASupprimer([['id' => 'abc', 'document' => 'Test']]),
    ])->assertSuccessful();

    Http::assertNothingSent();
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:supprimer-relations', [
        '--liste' => fichierASupprimer([['id' => 'abc', 'document' => 'Test']]),
        '--execute' => true,
    ])->assertFailed();
});

it('appelle DELETE /relations/{id} pour chaque entrée avec --execute', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:supprimer-relations', [
        '--liste' => fichierASupprimer([
            ['id' => 'aaa', 'document' => 'X → Y'],
            ['id' => 'bbb', 'document' => 'Y → Z'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/relations/aaa'));
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/relations/bbb'));

    putenv('MIBEKO_API_TOKEN');
});
