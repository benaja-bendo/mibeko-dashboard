<?php

namespace App\Console\Commands;

use App\Ai\CorpusVersion;
use Illuminate\Console\Command;

/**
 * Invalide le cache de réponses de l'assistant sur une cible donnée.
 *
 * `CorpusVersion::bump()` écrit via la façade `Cache`, qui résout le store
 * `database` sur `config('cache.stores.database.connection')` — jamais sur la
 * connexion passée à une autre commande via `--connection`. Sans ce correctif,
 * toute correction de contenu poussée en production (ex. `mibeko:fusionner-
 * fragments --connection=pgsql_prod_rw`) laisse le cache de l'assistant intact :
 * il continue de servir des réponses citant le texte d'avant la correction.
 * Documenté comme limite connue dans `FusionnerFragmentsCommand` (18/08/2026),
 * cette commande la comble sans jamais toucher au `.env`.
 *
 *   php artisan mibeko:invalider-cache-corpus --connection=pgsql_prod_rw
 */
class InvaliderCacheCorpusCommand extends Command
{
    protected $signature = 'mibeko:invalider-cache-corpus
        {--connection= : Connexion cible du store cache `database` (défaut : celle de l\'appli ; pgsql_prod_rw pour la production)}';

    protected $description = 'Bump le jeton CorpusVersion sur la connexion cible, pour que l\'assistant cesse de servir des réponses citant un texte corrigé.';

    public function handle(): int
    {
        $connexion = (string) ($this->option('connection') ?: config('database.default'));

        if ($connexion === 'pgsql_prod_ro') {
            $this->error('pgsql_prod_ro est un profil de LECTURE : cette commande écrit une clé de cache, elle exige pgsql_prod_rw (ou la connexion par défaut en développement).');

            return self::FAILURE;
        }

        CorpusVersion::bump($connexion);

        $this->info("Cache de l'assistant invalidé sur la connexion « {$connexion} ».");

        return self::SUCCESS;
    }
}
