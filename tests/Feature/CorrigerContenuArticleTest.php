<?php

use Illuminate\Support\Facades\Http;

function fichierContenus(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'contenu_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

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
