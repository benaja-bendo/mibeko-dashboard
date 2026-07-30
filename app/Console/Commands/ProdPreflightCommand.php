<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Contrôle avant vol d'une session de diagnostic sur la base de PRODUCTION.
 *
 * Cette commande est le seul point d'entrée recommandé pour regarder la prod. Elle
 * refuse de travailler sur une connexion autre que les connexions dédiées, prouve
 * que la session ne peut pas écrire, puis dresse un état des lieux du corpus.
 *
 * Prérequis : un tunnel SSH ouvert (`ssh -N -L 5434:127.0.0.1:5432 ubuntu@<IP_VPS>`)
 * et les variables PROD_RO_DB_* renseignées. Voir docs/infra/acces-prod-diagnostic.md.
 *
 * Invariants :
 * - Aucune migration ni aucun seeder ne doit jamais viser `pgsql_prod_ro` ou
 *   `pgsql_prod_rw` : ces connexions ne sont pas la connexion par défaut et ne
 *   doivent être passées ni à `migrate --database`, ni à `db:seed --database`.
 * - Aucun modèle Eloquent n'est utilisé ici : les modèles sont liés à la connexion
 *   par défaut et interrogeraient le développement sans que cela se voie.
 * - La seule écriture émise est la sonde de lecture seule, toujours annulée.
 */
class ProdPreflightCommand extends Command
{
    /**
     * Connexions autorisées. Toute autre connexion est refusée, pour qu'une faute de
     * frappe ne puisse pas transformer ce diagnostic en inspection du développement.
     */
    private const CONNEXIONS_AUTORISEES = ['pgsql_prod_ro', 'pgsql_prod_rw'];

    /** SQLSTATE : écriture refusée car la transaction est en lecture seule. */
    private const SQLSTATE_LECTURE_SEULE = '25006';

    /** SQLSTATE : écriture refusée car le rôle n'a pas le privilège. */
    private const SQLSTATE_PRIVILEGE_INSUFFISANT = '42501';

    protected $signature = 'mibeko:prod-preflight
        {--connection=pgsql_prod_ro : Connexion à contrôler (pgsql_prod_ro ou pgsql_prod_rw)}';

    protected $description = 'Contrôle une session de diagnostic prod : prouve la lecture seule puis dresse l\'état des lieux du corpus.';

    public function handle(): int
    {
        $nom = (string) $this->option('connection');

        if (! in_array($nom, self::CONNEXIONS_AUTORISEES, true)) {
            $this->error("Connexion « {$nom} » refusée.");
            $this->line('Connexions autorisées : '.implode(', ', self::CONNEXIONS_AUTORISEES).'.');
            $this->line('Cette commande ne sert qu\'au diagnostic de la production.');

            return self::FAILURE;
        }

        $config = config("database.connections.{$nom}");

        if (! is_array($config)) {
            $this->error("La connexion « {$nom} » n'est pas déclarée dans config/database.php.");

            return self::FAILURE;
        }

        if (($manquantes = $this->variablesManquantes($nom, $config)) !== []) {
            $this->error('Variables d\'environnement manquantes : '.implode(', ', $manquantes).'.');
            $this->newLine();
            $this->line('Rappels :');
            $this->line('  1. Ouvrir le tunnel : ssh -N -L 5434:127.0.0.1:5432 ubuntu@<IP_VPS>');
            $this->line('  2. Renseigner les variables PROD_RO_DB_* (cf. .env.example).');
            $this->line('  Les variables PROD_RW_DB_* ne doivent jamais être écrites dans un fichier :');
            $this->line('  elles s\'exportent à la main, pour une opération unique autorisée par un humain.');

            return self::FAILURE;
        }

        $attenduEnEcriture = $nom === 'pgsql_prod_rw';

        $this->afficherBandeau($nom, $config, $attenduEnEcriture);

        $connexion = DB::connection($nom);

        try {
            $connexion->getPdo();
        } catch (\Throwable $e) {
            $this->error('Connexion impossible : '.$e->getMessage());
            $this->line('Le tunnel SSH est-il bien ouvert ? Vérifier : lsof -nP -iTCP:'.$config['port'].' -sTCP:LISTEN');

            return self::FAILURE;
        }

        if (! $this->prouverComportementAttendu($connexion, $attenduEnEcriture)) {
            return self::FAILURE;
        }

        $this->etatDesLieux($connexion);
        $this->rappelerLesRegles($attenduEnEcriture);

        return self::SUCCESS;
    }

    /**
     * Liste les variables d'environnement requises qui ne sont pas renseignées.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function variablesManquantes(string $nom, array $config): array
    {
        $prefixe = $nom === 'pgsql_prod_rw' ? 'PROD_RW_DB_' : 'PROD_RO_DB_';

        $manquantes = [];

        foreach (['HOST' => 'host', 'PORT' => 'port', 'DATABASE' => 'database', 'USERNAME' => 'username', 'PASSWORD' => 'password'] as $suffixe => $cle) {
            if (blank($config[$cle] ?? null)) {
                $manquantes[] = $prefixe.$suffixe;
            }
        }

        return $manquantes;
    }

    /**
     * Affiche la cible interrogée. Le mot de passe n'est jamais affiché.
     *
     * @param  array<string, mixed>  $config
     */
    private function afficherBandeau(string $nom, array $config, bool $attenduEnEcriture): void
    {
        $this->newLine();
        $this->error('  ███  PRODUCTION  ███  ');
        $this->newLine();

        $this->table(['Cible', 'Valeur'], [
            ['Connexion', $nom],
            ['Hôte', $config['host']],
            ['Port', $config['port']],
            ['Base', $config['database']],
            ['Utilisateur', $config['username']],
            ['Mode attendu', $attenduEnEcriture ? 'ÉCRITURE (escalade autorisée par un humain)' : 'lecture seule'],
        ]);

        if ($attenduEnEcriture) {
            $this->warn('Connexion d\'escalade : cette session PEUT écrire en production.');
            $this->warn('Elle ne doit servir qu\'à l\'opération unique qui a été explicitement autorisée.');
            $this->newLine();
        }
    }

    /**
     * Prouve que la session se comporte comme attendu, en émettant une sonde d'écriture
     * inoffensive (aucune ligne ne peut correspondre) toujours annulée.
     *
     * Le point délicat est de ne pas conclure « c'est sûr » sur un échec quelconque :
     * une table absente ou une faute de syntaxe échoueraient aussi. Seuls les SQLSTATE
     * 25006 (transaction en lecture seule) et 42501 (privilège insuffisant) prouvent
     * quelque chose ; tout autre code rend la preuve non concluante.
     */
    private function prouverComportementAttendu(Connection $connexion, bool $attenduEnEcriture): bool
    {
        $sonde = 'update legal_documents set updated_at = updated_at where id is null';

        $transactionOuverte = false;
        $ecritureAcceptee = false;
        $sqlstate = null;
        $messageErreur = null;

        try {
            $connexion->beginTransaction();
            $transactionOuverte = true;

            $connexion->statement($sonde);
            $ecritureAcceptee = true;
        } catch (QueryException $e) {
            $sqlstate = (string) $e->getCode();
            $messageErreur = $e->getMessage();
        } catch (\Throwable $e) {
            $messageErreur = $e->getMessage();
        } finally {
            if ($transactionOuverte) {
                try {
                    $connexion->rollBack();
                } catch (\Throwable $e) {
                    $this->error('Annulation de la sonde impossible : '.$e->getMessage());
                }
            }
        }

        if ($ecritureAcceptee) {
            if ($attenduEnEcriture) {
                $this->warn('Sonde d\'écriture acceptée : conforme à une connexion d\'escalade (annulée).');
                $this->newLine();

                return true;
            }

            $this->newLine();
            $this->error('  ███  CETTE CONNEXION PEUT ÉCRIRE EN PRODUCTION  ███  ');
            $this->error('La sonde d\'écriture a été acceptée alors que la lecture seule était attendue.');
            $this->line('Cause probable : le rôle Postgres utilisé n\'est pas le rôle dédié en lecture seule.');
            $this->line('À corriger côté serveur (GRANT SELECT + default_transaction_read_only = on)');
            $this->line('avant toute session de diagnostic. Voir docs/infra/acces-prod-diagnostic.md.');
            $this->newLine();

            return false;
        }

        if ($sqlstate === self::SQLSTATE_LECTURE_SEULE) {
            $this->info('Lecture seule prouvée (SQLSTATE 25006 : transaction en lecture seule).');
            $this->newLine();

            return true;
        }

        if ($sqlstate === self::SQLSTATE_PRIVILEGE_INSUFFISANT) {
            $this->info('Écriture refusée par privilège insuffisant (SQLSTATE 42501).');
            $this->warn('La session n\'est pas marquée en lecture seule, mais le rôle n\'a pas le droit d\'écrire.');
            $this->warn('Recommandé : ajouter aussi ALTER ROLE ... SET default_transaction_read_only = on.');
            $this->newLine();

            return true;
        }

        $this->newLine();
        $this->error('Lecture seule NON prouvée.');
        $this->line('La sonde a échoué, mais pour une raison qui ne prouve rien'
            .($sqlstate !== null ? " (SQLSTATE {$sqlstate})" : '').' :');
        $this->line('  '.($messageErreur ?? 'erreur inconnue'));
        $this->line('Un échec pour une autre cause (table absente, schéma inattendu) ne garantit pas');
        $this->line('que la session est incapable d\'écrire : session interrompue par précaution.');
        $this->newLine();

        return false;
    }

    /**
     * État des lieux du corpus, en lecture seule. Les tables porteuses de SoftDeletes
     * sont comptées en distinguant les lignes vivantes des lignes supprimées.
     */
    private function etatDesLieux(Connection $connexion): void
    {
        $version = $connexion->selectOne('select current_setting(\'server_version\') as version');
        $this->line('PostgreSQL : '.($version->version ?? 'inconnue'));
        $this->newLine();

        $extensions = $connexion->table('pg_extension')
            ->whereIn('extname', ['ltree', 'vector', 'btree_gist', 'pg_trgm'])
            ->pluck('extname')
            ->all();

        $this->table(['Extension', 'Présente'], collect(['ltree', 'vector', 'btree_gist', 'pg_trgm'])
            ->map(fn (string $nom): array => [$nom, in_array($nom, $extensions, true) ? 'oui' : 'NON'])
            ->all());

        $documents = $connexion->table('legal_documents')
            ->selectRaw('curation_status, count(*) filter (where deleted_at is null) as vivants, count(*) filter (where deleted_at is not null) as supprimes')
            ->groupBy('curation_status')
            ->orderBy('curation_status')
            ->get();

        $this->table(
            ['Statut de curation', 'Vivants', 'Soft-deleted'],
            $documents->map(fn ($ligne): array => [
                $ligne->curation_status ?? '(nul)',
                $ligne->vivants,
                $ligne->supprimes,
            ])->all()
        );

        $flags = $connexion->table('curation_flags')
            ->selectRaw('severity, count(*) as total')
            ->where('resolved', false)
            ->groupBy('severity')
            ->orderBy('severity')
            ->get();

        $this->table(
            ['Anomalie non résolue (gravité)', 'Total'],
            $flags->isEmpty()
                ? [['(aucune)', 0]]
                : $flags->map(fn ($ligne): array => [$ligne->severity ?? '(nul)', $ligne->total])->all()
        );

        $this->table(['Compteur', 'Valeur'], [
            ['Articles vivants', $connexion->table('articles')->whereNull('deleted_at')->count()],
            ['Articles soft-deleted', $connexion->table('articles')->whereNotNull('deleted_at')->count()],
            ['Versions d\'articles', $connexion->table('article_versions')->count()],
            ['Versions sans embedding', $connexion->table('article_versions')->whereNull('embedding')->count()],
            ['Journaux officiels vivants', $connexion->table('official_journals')->whereNull('deleted_at')->count()],
            ['Fichiers média', $connexion->table('media_files')->count()],
        ]);
    }

    private function rappelerLesRegles(bool $attenduEnEcriture): void
    {
        $this->newLine();
        $this->line('Règles de la session :');
        $this->line('  · Lecture seule par défaut : le diagnostic ne modifie jamais la production.');
        $this->line('  · Toute écriture exige une autorisation humaine explicite, opération par opération.');
        $this->line('  · Une autorisation ne vaut que pour l\'opération nommée, jamais pour la suivante.');
        $this->line('  · Toute écriture est précédée d\'un dump frais et livrée sous forme rejouable');
        $this->line('    (commande artisan ou script), jamais en SQL ad hoc.');
        $this->line('  · La publication passe par l\'API Laravel, jamais par un UPDATE de curation_status.');

        if ($attenduEnEcriture) {
            $this->newLine();
            $this->warn('Fin d\'opération : retirer les variables PROD_RW_DB_* du shell et fermer le tunnel.');
        }
    }
}
