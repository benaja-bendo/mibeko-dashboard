<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function fichierContenus(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'contenu_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

beforeEach(function () {
    Sleep::fake();
});

it('n\'émet aucun appel réseau sans --execute', function () {
    Http::fake();

    $this->artisan('mibeko:corriger-contenu-article', [
        '--mapping' => fichierContenus([['id' => 'abc', 'document' => 'Test', 'content' => 'Texte propre.']]),
    ])->assertSuccessful();

    Http::assertNothingSent();
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:corriger-contenu-article', [
        '--mapping' => fichierContenus([['id' => 'abc', 'document' => 'Test', 'content' => 'Texte propre.']]),
        '--execute' => true,
    ])->assertFailed();
});

it('envoie un PATCH content par article avec --execute', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:corriger-contenu-article', [
        '--mapping' => fichierContenus([
            ['id' => 'aaa', 'document' => 'Loi X', 'content' => 'Formule de promulgation.'],
            ['id' => 'bbb', 'document' => 'Arrêté Y', 'content' => 'Vu la Constitution ; Arrêtent :'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_contains($r->url(), '/articles/aaa') && $r['content'] === 'Formule de promulgation.');
    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_contains($r->url(), '/articles/bbb') && $r['content'] === 'Vu la Constitution ; Arrêtent :');

    putenv('MIBEKO_API_TOKEN');
});

it('reprend après un 429 en respectant le Retry-After du serveur', function () {
    // Régression du 04/08/2026 : lancée sans cadence ni reprise sur ~700
    // articles, cette commande a épuisé le quota throttle:api après 279
    // correctifs puis échoué en 429 sur tout le reste, sans un seul retry.
    putenv('MIBEKO_API_TOKEN=jeton-de-test');

    $appels = 0;
    Http::fake(function () use (&$appels) {
        $appels++;

        return $appels === 1
            ? Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '7'])
            : Http::response(['success' => true], 200);
    });

    $this->artisan('mibeko:corriger-contenu-article', [
        '--mapping' => fichierContenus([['id' => 'abc', 'document' => 'Un arrêté', 'content' => 'Texte propre.']]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);
    Sleep::assertSlept(fn ($duration) => $duration->s === 7, times: 1);

    putenv('MIBEKO_API_TOKEN');
});

it('écrit les articles non corrigés au format --mapping pour permettre la reprise', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');

    Http::fake(fn ($request) => str_contains($request->url(), 'aaa')
        ? Http::response(['message' => 'Service Unavailable'], 503)
        : Http::response(['success' => true], 200));

    $echecs = tempnam(sys_get_temp_dir(), 'echecs_').'.json';

    $this->artisan('mibeko:corriger-contenu-article', [
        '--mapping' => fichierContenus([
            ['id' => 'aaa', 'document' => 'Document malchanceux', 'motif' => 'x', 'content' => 'Texte A.'],
            ['id' => 'bbb', 'document' => 'Document valide', 'motif' => 'x', 'content' => 'Texte B.'],
        ]),
        '--echecs' => $echecs,
        '--execute' => true,
    ])->assertSuccessful();

    expect(json_decode((string) file_get_contents($echecs), true))
        ->toBe([['id' => 'aaa', 'document' => 'Document malchanceux', 'motif' => 'x', 'content' => 'Texte A.']]);

    putenv('MIBEKO_API_TOKEN');
});

it('tient la cadence entre les articles, jamais après le dernier', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:corriger-contenu-article', [
        '--mapping' => fichierContenus([
            ['id' => 'aaa', 'document' => 'Premier', 'content' => 'A.'],
            ['id' => 'bbb', 'document' => 'Deuxième', 'content' => 'B.'],
            ['id' => 'ccc', 'document' => 'Troisième', 'content' => 'C.'],
        ]),
        '--rythme' => 30,
        '--execute' => true,
    ])->assertSuccessful();

    // Trois articles, deux pauses : le lot ne s'attarde pas après le dernier.
    Sleep::assertSleptTimes(2);

    putenv('MIBEKO_API_TOKEN');
});
