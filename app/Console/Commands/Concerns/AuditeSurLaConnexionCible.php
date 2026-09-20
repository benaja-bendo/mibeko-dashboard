<?php

namespace App\Console\Commands\Concerns;

use Closure;

/**
 * `owen-it/auditing` résout la connexion de ses lignes `audits` sur
 * `getConnectionName()`, codé en dur dans le package pour renvoyer
 * `config('audit.drivers.database.connection')` — jamais la connexion du
 * modèle réellement audité (dashboard#169). Sans ce correctif, une commande
 * classe 2 écrivant sur `--connection=pgsql_prod_rw` via `Model::on($connexion)`
 * voit ses lignes d'audit atterrir dans la connexion PAR DÉFAUT de
 * l'application (la base de développement), jamais dans `pgsql_prod_rw`.
 *
 * Bascule temporaire de ce seul réglage de config, le temps de l'écriture :
 * c'est le point d'extension que le package expose réellement (aucun moyen
 * de faire résoudre l'Audit sur la connexion du modèle audité autrement, son
 * driver `Database` instancie une ligne neuve sans lien avec l'instance
 * appelante). Restaurée même si l'écriture lève une exception.
 */
trait AuditeSurLaConnexionCible
{
    private function avecAuditSurConnexion(string $connexion, Closure $callback): mixed
    {
        $precedente = config('audit.drivers.database.connection');
        config(['audit.drivers.database.connection' => $connexion]);

        try {
            return $callback();
        } finally {
            config(['audit.drivers.database.connection' => $precedente]);
        }
    }
}
