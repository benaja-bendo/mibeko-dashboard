<?php

use Illuminate\Support\Facades\Http;

function fichierArticles(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'masthead_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

it('n\'émet aucun appel réseau sans --execute', function () {
    Http::fake();

    $this->artisan('mibeko:retirer-articles-masthead', [
        '--liste' => fichierArticles([['id' => 'abc', 'document' => 'Test', 'numero_article' => 'PREAMBULE']]),
    ])->assertSuccessful();

    Http::assertNothingSent();
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:retirer-articles-masthead', [
        '--liste' => fichierArticles([['id' => 'abc', 'document' => 'Test', 'numero_article' => 'PREAMBULE']]),
        '--execute' => true,
    ])->assertFailed();
});

it('appelle DELETE /articles/{id} pour chaque entrée avec --execute', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:retirer-articles-masthead', [
        '--liste' => fichierArticles([
            ['id' => 'aaa', 'document' => 'Constitution X', 'numero_article' => 'PREAMBULE'],
            ['id' => 'bbb', 'document' => 'Constitution X', 'numero_article' => 'TABLEAU_1'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/articles/aaa'));
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/articles/bbb'));

    putenv('MIBEKO_API_TOKEN');
});

it('continue le lot après un échec et le signale', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');

    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;

        return $appels === 1
            ? Http::response(['message' => 'Article non trouvé.'], 404)
            : Http::response(['success' => true], 200);
    });

    $this->artisan('mibeko:retirer-articles-masthead', [
        '--liste' => fichierArticles([
            ['id' => 'introuvable', 'document' => 'X', 'numero_article' => 'PREAMBULE'],
            ['id' => 'valide', 'document' => 'X', 'numero_article' => 'TABLEAU_1'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);

    putenv('MIBEKO_API_TOKEN');
});
