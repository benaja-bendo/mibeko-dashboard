<?php

/**
 * Garde-fous de la commande de diagnostic sur la base de PRODUCTION.
 *
 * Ce qui est testé ici est volontairement limité à ce qui est vérifiable sans
 * aucune production : la commande doit refuser toute connexion qui n'est pas une
 * connexion de diagnostic dédiée, et refuser de partir si la cible n'est pas
 * complètement renseignée. Aucun test n'ouvre de connexion réseau.
 *
 * La preuve de lecture seule elle-même (SQLSTATE 25006 / 42501) ne peut être
 * vérifiée que contre un vrai serveur : c'est le rôle du préflight à l'exécution.
 */
it('refuse une connexion qui n\'est pas une connexion de diagnostic', function () {
    $this->artisan('mibeko:prod-preflight', ['--connection' => 'pgsql'])
        ->expectsOutputToContain('Connexion « pgsql » refusée.')
        ->assertExitCode(1);
});

it('refuse la connexion par défaut de l\'application', function () {
    $this->artisan('mibeko:prod-preflight', ['--connection' => config('database.default')])
        ->assertExitCode(1);
});

it('refuse de partir quand la cible de production n\'est pas renseignée', function () {
    config([
        'database.connections.pgsql_prod_ro.database' => null,
        'database.connections.pgsql_prod_ro.username' => null,
        'database.connections.pgsql_prod_ro.password' => null,
    ]);

    // Une seule assertion de contenu : chaque appel à expectsOutputToContain()
    // consomme une ligne de sortie, deux appels viseraient donc deux lignes.
    $this->artisan('mibeko:prod-preflight')
        ->expectsOutputToContain('manquantes : PROD_RO_DB_DATABASE')
        ->assertExitCode(1);
});

it('nomme les variables d\'écriture quand la connexion d\'escalade est incomplète', function () {
    config([
        'database.connections.pgsql_prod_rw.host' => null,
        'database.connections.pgsql_prod_rw.port' => null,
        'database.connections.pgsql_prod_rw.database' => null,
        'database.connections.pgsql_prod_rw.username' => null,
        'database.connections.pgsql_prod_rw.password' => null,
    ]);

    $this->artisan('mibeko:prod-preflight', ['--connection' => 'pgsql_prod_rw'])
        ->expectsOutputToContain('PROD_RW_DB_HOST')
        ->assertExitCode(1);
});

it('déclare la connexion de diagnostic sur un port distinct du développement', function () {
    $ro = config('database.connections.pgsql_prod_ro');

    expect($ro)->toBeArray()
        ->and($ro['driver'])->toBe('pgsql')
        ->and($ro['search_path'])->toBe('public');

    // 5433 est le Postgres de développement : la cible de diagnostic ne doit
    // jamais lui ressembler, sinon la prod devient indiscernable du dev.
    expect((string) $ro['port'])->not->toBe('5433')
        ->and((string) $ro['port'])->not->toBeEmpty();
});

it('ne fournit aucune valeur par défaut à la connexion d\'écriture', function () {
    // Sans export explicite dans le shell, la connexion d'escalade doit être
    // inutilisable : aucun défaut ne doit la rendre silencieusement fonctionnelle.
    $rw = config('database.connections.pgsql_prod_rw');

    expect($rw)->toBeArray()
        ->and($rw['host'])->toBeNull()
        ->and($rw['port'])->toBeNull()
        ->and($rw['database'])->toBeNull()
        ->and($rw['username'])->toBeNull()
        ->and($rw['password'])->toBeNull();
});

it('déclare le disque de diagnostic MinIO sur un port distinct du développement', function () {
    $disque = config('filesystems.disks.s3_prod_ro');

    expect($disque)->toBeArray()
        ->and($disque['driver'])->toBe('s3')
        ->and($disque['use_path_style_endpoint'])->toBeTrue()
        ->and($disque['endpoint'])->toBe('http://127.0.0.1:9100')
        ->and($disque['endpoint'])->not->toContain(':9000');
});
