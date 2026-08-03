<?php

use Illuminate\Support\Facades\Http;

function fichierTypeCodes(array $contenu): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'typecode_').'.json';
    file_put_contents($chemin, json_encode($contenu, JSON_UNESCAPED_UNICODE));

    return $chemin;
}

it('n\'émet aucun appel réseau sans --execute', function () {
    Http::fake();

    $this->artisan('mibeko:corriger-type-code', [
        '--mapping' => fichierTypeCodes([['id' => 'abc', 'titre' => 'Test', 'type_code' => 'LOI']]),
    ])->assertSuccessful();

    Http::assertNothingSent();
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');

    $this->artisan('mibeko:corriger-type-code', [
        '--mapping' => fichierTypeCodes([['id' => 'abc', 'titre' => 'Test', 'type_code' => 'LOI']]),
        '--execute' => true,
    ])->assertFailed();
});

it('envoie un PATCH type_code par document avec --execute', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $this->artisan('mibeko:corriger-type-code', [
        '--mapping' => fichierTypeCodes([
            ['id' => 'aaa', 'titre' => 'Loi X', 'type_code' => 'LOI'],
            ['id' => 'bbb', 'titre' => 'Arrêté Y', 'type_code' => 'ARR'],
        ]),
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_contains($r->url(), '/legal-documents/aaa') && $r['type_code'] === 'LOI');
    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_contains($r->url(), '/legal-documents/bbb') && $r['type_code'] === 'ARR');

    putenv('MIBEKO_API_TOKEN');
});
