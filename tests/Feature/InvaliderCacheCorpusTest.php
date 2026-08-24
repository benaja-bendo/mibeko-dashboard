<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Lit directement le store `database` (jamais `CorpusVersion::current()`, qui
 * lit le store PAR DÉFAUT — `array` en test, per `phpunit.xml` — donc jamais
 * ce que cette commande écrit explicitement sur `database`. C'est exactement
 * la confusion de store que la commande existe pour corriger en production.
 */
function jetonCorpusEnBase(): ?string
{
    return Cache::store('database')->get('mibeko:corpus_version');
}

it('bump le jeton du corpus sur la connexion par défaut sans option', function () {
    $avant = jetonCorpusEnBase();

    $this->artisan('mibeko:invalider-cache-corpus')->assertSuccessful();

    expect(jetonCorpusEnBase())->not->toBe($avant);
});

it('bump le jeton sur la connexion demandée', function () {
    $avant = jetonCorpusEnBase();

    $this->artisan('mibeko:invalider-cache-corpus', ['--connection' => 'pgsql'])->assertSuccessful();

    expect(jetonCorpusEnBase())->not->toBe($avant);
});

it('refuse la connexion en lecture seule', function () {
    $this->artisan('mibeko:invalider-cache-corpus', ['--connection' => 'pgsql_prod_ro'])->assertFailed();
});
