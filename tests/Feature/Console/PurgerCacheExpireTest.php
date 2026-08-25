<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Écrit une entrée de cache directement en table, avec l'expiration voulue. */
function poserEntreeCache(string $cle, int $expiration): void
{
    DB::table('cache')->insert([
        'key' => $cle,
        'value' => serialize('peu importe'),
        'expiration' => $expiration,
    ]);
}

it('supprime les entrées expirées et épargne les vivantes', function () {
    $passe = now()->subHour()->getTimestamp();
    $futur = now()->addHour()->getTimestamp();

    poserEntreeCache('vieille-cle-1', $passe);
    poserEntreeCache('vieille-cle-2', $passe);
    // Les entrées vivantes sont surtout des embeddings de requêtes : les purger
    // à tort coûterait un appel réseau à la prochaine recherche.
    poserEntreeCache('mibeko-cache-laravel-embeddings:vivante', $futur);

    $this->artisan('mibeko:purger-cache-expire')
        ->expectsOutputToContain('cache : 2 entrée(s) expirée(s) purgée(s)')
        ->assertSuccessful();

    expect(DB::table('cache')->pluck('key')->all())
        ->toBe(['mibeko-cache-laravel-embeddings:vivante']);
});

it('ne supprime rien en simulation', function () {
    poserEntreeCache('vieille-cle', now()->subHour()->getTimestamp());

    $this->artisan('mibeko:purger-cache-expire', ['--dry-run' => true])
        ->expectsOutputToContain('Simulation : 1 entrée(s) seraient purgées.')
        ->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(1);
});

it('refuse le profil de production en lecture seule', function () {
    // Le garde-fou vaut pour toute commande qui écrit : un profil de lecture qui
    // échoue au fond du traitement coûte plus cher à diagnostiquer qu'un refus.
    $this->artisan('mibeko:purger-cache-expire', ['--connection' => 'pgsql_prod_ro'])
        ->expectsOutputToContain('pgsql_prod_ro est un profil de LECTURE')
        ->assertFailed();
});

it('épargne une entrée qui expire exactement maintenant', function () {
    // Frontière : `expiration = maintenant` n'est pas encore dépassée. La
    // comparaison doit rester stricte, sinon la purge devance le cache lui-même.
    poserEntreeCache('cle-limite', now()->getTimestamp());

    $this->artisan('mibeko:purger-cache-expire')->assertSuccessful();

    expect(DB::table('cache')->where('key', 'cle-limite')->exists())->toBeTrue();
});
