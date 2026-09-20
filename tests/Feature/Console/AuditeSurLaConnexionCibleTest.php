<?php

use App\Console\Commands\Concerns\AuditeSurLaConnexionCible;

/**
 * owen-it/auditing résout la connexion de ses lignes `audits` sur
 * `config('audit.drivers.database.connection')`, jamais sur celle du modèle
 * audité (dashboard#169) — c'est le seul point d'extension que le package
 * expose réellement. Ce test vérifie le mécanisme lui-même : bascule pendant
 * l'écriture, restauration après, y compris quand l'écriture échoue.
 */
function sujetAuditeSurConnexion(): object
{
    return new class
    {
        use AuditeSurLaConnexionCible;

        public function executer(string $connexion, Closure $callback): mixed
        {
            return $this->avecAuditSurConnexion($connexion, $callback);
        }
    };
}

it('bascule la connexion d\'audit pendant l\'écriture puis la restaure', function () {
    config(['audit.drivers.database.connection' => null]);

    $pendantLecallback = null;

    $resultat = sujetAuditeSurConnexion()->executer('pgsql_prod_rw', function () use (&$pendantLecallback) {
        $pendantLecallback = config('audit.drivers.database.connection');

        return 'fait';
    });

    expect($pendantLecallback)->toBe('pgsql_prod_rw')
        ->and(config('audit.drivers.database.connection'))->toBeNull()
        ->and($resultat)->toBe('fait');
});

it('restaure la connexion d\'audit même si l\'écriture lève une exception', function () {
    config(['audit.drivers.database.connection' => 'valeur-avant']);

    $lancee = null;

    try {
        sujetAuditeSurConnexion()->executer('pgsql_prod_rw', function () {
            throw new RuntimeException('échec simulé pendant l\'écriture');
        });
    } catch (RuntimeException $e) {
        $lancee = $e->getMessage();
    }

    expect($lancee)->toBe('échec simulé pendant l\'écriture')
        ->and(config('audit.drivers.database.connection'))->toBe('valeur-avant');
});
