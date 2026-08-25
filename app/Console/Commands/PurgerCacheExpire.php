<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Purge les entrées expirées du cache porté par la base.
 *
 * Avec le store `database`, Laravel n'oublie une entrée expirée que s'il la
 * relit : une clé jamais redemandée reste en table indéfiniment. Mesuré en
 * production le 25/08/2026, 2 036 des 2 302 lignes de `cache` étaient expirées
 * (88 %), pour 6 Mo — sans borne dans le temps, puisque rien ne les relit.
 * `cache:prune-stale-tags` ne répond pas au besoin : il ne traite que les tags,
 * et seulement sous Redis.
 *
 * Les entrées vivantes sont surtout des embeddings de requêtes de recherche
 * (~20 ko pièce, cf. `toEmbeddings(cache: true)`) : les purger à tort coûterait
 * un appel réseau à la prochaine recherche. D'où la comparaison stricte sur
 * `expiration`, qui ne touche que ce que le cache considère déjà comme mort.
 *
 * `cache_locks` est traité au passage, avec la même règle : un verrou dont
 * l'expiration est dépassée n'est plus tenu par personne.
 *
 *   php artisan mibeko:purger-cache-expire --dry-run
 *   php artisan mibeko:purger-cache-expire --connection=pgsql_prod_rw
 */
class PurgerCacheExpire extends Command
{
    protected $signature = 'mibeko:purger-cache-expire
        {--dry-run : Compter sans supprimer}
        {--connection= : Connexion cible (défaut : celle de l\'appli ; pgsql_prod_rw pour la production)}';

    protected $description = 'Supprime les entrées de cache dont la date d\'expiration est dépassée (store database).';

    public function handle(): int
    {
        $connexion = (string) ($this->option('connection') ?: config('database.default'));

        if ($connexion === 'pgsql_prod_ro') {
            $this->error('pgsql_prod_ro est un profil de LECTURE : cette commande supprime des lignes, elle exige pgsql_prod_rw (ou la connexion par défaut en développement).');

            return self::FAILURE;
        }

        $db = DB::connection($connexion);
        $maintenant = now()->getTimestamp();
        $simulation = (bool) $this->option('dry-run');
        $total = 0;

        foreach (['cache', 'cache_locks'] as $table) {
            if (! $db->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $requete = $db->table($table)->where('expiration', '<', $maintenant);
            $nombre = $simulation ? $requete->count() : $requete->delete();
            $total += $nombre;

            $this->line(sprintf('%s : %d entrée(s) expirée(s)%s', $table, $nombre, $simulation ? ' (simulation)' : ' purgée(s)'));
        }

        $this->info($simulation
            ? "Simulation : {$total} entrée(s) seraient purgées."
            : "Purge terminée : {$total} entrée(s) supprimée(s).");

        return self::SUCCESS;
    }
}
