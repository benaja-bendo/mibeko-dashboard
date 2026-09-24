<?php

namespace App\Console\Commands;

use App\Models\Dossier;
use App\Models\User;
use App\Models\UserSetting;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Efface définitivement les comptes supprimés depuis plus de N jours (30 par
 * défaut, `config('account_deletion.purge_after_days')`) — décision du
 * 24/09/2026, `docs/decisions.md`.
 *
 * `DELETE /v1/profile` (l'usager) comme `DELETE /v1/admin/users/{user}` (un
 * admin) ne font qu'un soft delete : le compte reste restaurable par un admin
 * pendant le délai, puis cette commande l'efface avec ce qu'il possède. Sans
 * elle, rien ne partait jamais : mesuré le 24/09/2026, 8 comptes supprimés
 * depuis le 26/06 gardaient 29 conversations, 2 dossiers et 1 618
 * notifications, et leurs lignes d'audit l'e-mail, le nom et l'IP en clair.
 *
 * Le sort de chaque table se lit dans le schéma, pas ici : une clé étrangère
 * vers `users` en CASCADE efface (conversations, dossiers, notifications,
 * réglages…), en SET NULL anonymise (consommation IA, historique de paiement
 * conservé dix ans, traces d'équipe), en RESTRICT retient le compte, qui est
 * alors sauté et signalé, jamais forcé. `PurgerComptesSupprimesCommandTest`
 * fige la règle de chaque clé : une nouvelle table rattachée à `users` doit
 * déclarer son sort pour passer les tests. Seuls les liens SANS clé étrangère
 * sont traités à la main (rôles, jetons, centres d'intérêt, sessions,
 * réinitialisation de mot de passe, invitation reçue), ainsi que le journal
 * `audits`.
 *
 * Écritures par le query builder, jamais par Eloquent : un `forceDelete()`
 * déclencherait l'audit owen-it de l'événement `deleted` (`audit.console` est
 * actif), qui recopierait le nom et l'e-mail dans une nouvelle ligne
 * `audits` au moment même où on les efface. Pour la même raison, la sortie
 * n'affiche jamais d'adresse : ce journal-ci ne doit pas devenir la copie de
 * ce qu'il efface.
 *
 *   php artisan mibeko:purger-comptes-supprimes                        # simulation
 *   php artisan mibeko:purger-comptes-supprimes --limit=1 --execute    # lot pilote
 *   php artisan mibeko:purger-comptes-supprimes --execute
 *
 * En production depuis un poste : `--connection=pgsql_prod_rw`, méthode du
 * `docs/infra/production.md` § 6 (la simulation accepte `pgsql_prod_ro`).
 */
class PurgerComptesSupprimesCommand extends Command
{
    /**
     * Tables qui survivent au compte, détachées (historique de paiement,
     * conservé dix ans). Si leur clé vers `users` est encore en CASCADE sur la
     * base visée — migration du 24/09/2026 pas appliquée —, le compte qui en
     * possède est sauté plutôt que d'effacer des preuves de paiement.
     */
    public const CONSERVEES = ['plan_grants', 'manual_payment_orders'];

    /**
     * Modèles audités dont les lignes partent avec le compte. Leurs lignes
     * `audits` gardent l'événement et la date, perdent les valeurs, l'IP et le
     * navigateur. Clé : table du modèle.
     */
    public const AUDITS_A_EFFACER = [
        'users' => User::class,
        'user_settings' => UserSetting::class,
        'dossiers' => Dossier::class,
    ];

    protected $signature = 'mibeko:purger-comptes-supprimes
        {--connection= : Connexion cible (défaut : celle de l\'appli ; pgsql_prod_rw pour la production depuis un poste)}
        {--days= : Délai en jours depuis la suppression (défaut : config account_deletion.purge_after_days)}
        {--limit=0 : Ne traiter que les N comptes supprimés les plus anciens (lot pilote)}
        {--execute : Efface réellement. Sans cette option, simulation seule.}';

    protected $description = 'Efface définitivement les comptes supprimés depuis plus de N jours, avec ce qu\'ils possèdent.';

    public function handle(): int
    {
        $connexion = (string) ($this->option('connection') ?: config('database.default'));
        $execute = (bool) $this->option('execute');

        if ($execute && $connexion === 'pgsql_prod_ro') {
            $this->error('pgsql_prod_ro est un profil de LECTURE : il suffit à la simulation, l\'effacement exige pgsql_prod_rw.');

            return self::FAILURE;
        }

        $jours = max(1, (int) ($this->option('days') ?? config('account_deletion.purge_after_days')));
        $db = DB::connection($connexion);
        $seuil = now()->subDays($jours);

        $comptes = $this->comptesEchus($db, $seuil);
        $total = count($comptes);
        $this->info("Comptes supprimés depuis plus de {$jours} j sur « {$connexion} » : {$total}.");

        if ($total === 0) {
            return self::SUCCESS;
        }

        $limite = max(0, (int) $this->option('limit'));
        if ($limite > 0) {
            $comptes = array_slice($comptes, 0, $limite);
        }

        $cles = $this->clesEtrangeres($db);
        $mesures = [];
        foreach ($comptes as $compte) {
            $mesures[$compte->id] = $this->mesurer($db, $compte, $cles);
        }

        $this->afficherMesures($comptes, $mesures);

        $aPurger = array_values(array_filter($comptes, fn (object $c) => $mesures[$c->id]['blocages'] === []));
        $sautes = count($comptes) - count($aPurger);

        if (! $execute) {
            $this->newLine();
            $this->info(sprintf(
                'Simulation : %d compte(s) à effacer, %d ligne(s) effacée(s), %d anonymisée(s), %d compte(s) sauté(s).',
                count($aPurger),
                array_sum(array_map(fn (object $c) => array_sum($mesures[$c->id]['efface']), $aPurger)),
                array_sum(array_map(fn (object $c) => array_sum($mesures[$c->id]['anonymise']), $aPurger)),
                $sautes,
            ));
            $this->warn('SIMULATION — rien n\'est effacé. Ajouter --execute pour purger.');

            return self::SUCCESS;
        }

        $purges = 0;
        $echecs = 0;
        foreach ($aPurger as $compte) {
            try {
                $this->purger($db, $compte, $seuil, $cles);
                $purges++;
                $this->line('<fg=green>✓</> '.$this->libelle($compte).' effacé');
            } catch (Throwable $e) {
                $echecs++;
                // Le message d'une erreur SQL recopie la requête et ses valeurs,
                // e-mail compris : seul son code part dans le journal.
                $motif = $e instanceof QueryException ? 'erreur SQL '.$e->getCode().', transaction annulée' : $e->getMessage();
                $this->line('<fg=red>✗</> '.$this->libelle($compte).' — '.$motif);
            }
        }

        $this->newLine();
        $this->info("{$purges} compte(s) effacé(s), {$sautes} sauté(s), {$echecs} en échec.");

        // Mesure d'après : ne restent que les comptes sautés, en échec ou hors
        // du lot pilote (--limit).
        $reste = count($this->comptesEchus($db, $seuil));
        $this->info("Comptes supprimés depuis plus de {$jours} j après passage : {$reste} (avant : {$total}).");

        if ($sautes > 0) {
            $this->warn("{$sautes} compte(s) retenu(s) par une clé RESTRICT, un paiement ou un abonnement Stripe : à trancher à la main, la commande ne force jamais.");
        }

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<object>
     */
    private function comptesEchus(ConnectionInterface $db, CarbonInterface $seuil): array
    {
        return $db->table('users')
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $seuil)
            ->orderBy('deleted_at')
            ->get(['id', 'email', 'deleted_at'])
            ->all();
    }

    /**
     * Clés étrangères mono-colonne du schéma : c'est là, et nulle part
     * ailleurs, que se lit le sort de chaque table au départ d'un compte.
     *
     * @return list<array{enfant: string, colonne: string, parent: string, cible: string, regle: string}>
     */
    private function clesEtrangeres(ConnectionInterface $db): array
    {
        $lignes = $db->select(<<<'SQL'
            select c.conrelid::regclass::text as enfant,
                   a.attname as colonne,
                   c.confrelid::regclass::text as parent,
                   pa.attname as cible,
                   case c.confdeltype
                       when 'c' then 'cascade'
                       when 'n' then 'set null'
                       when 'd' then 'set default'
                       else 'restrict'
                   end as regle
            from pg_constraint c
            join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
            join pg_attribute pa on pa.attrelid = c.confrelid and pa.attnum = c.confkey[1]
            where c.contype = 'f'
              and cardinality(c.conkey) = 1
              and c.connamespace = current_schema()::regnamespace
            order by 1, 2
            SQL);

        return array_map(fn (object $cle) => (array) $cle, $lignes);
    }

    /**
     * Pour chaque table que la suppression du compte atteint en CASCADE, le
     * prédicat SQL qui désigne les lignes qu'elle emporte.
     *
     * @param  list<array{enfant: string, colonne: string, parent: string, cible: string, regle: string}>  $cles
     * @return array<string, string>
     */
    private function perimetre(ConnectionInterface $db, string $compteId, array $cles): array
    {
        $racine = 'id = '.$db->getPdo()->quote($compteId);
        $predicats = ['users' => $racine];

        // Point fixe : une table atteinte par deux chemins (les messages, par
        // le compte et par leur conversation) cumule ses prédicats.
        for ($passe = 0; $passe < 10; $passe++) {
            $suivants = ['users' => $racine];

            foreach ($cles as $cle) {
                if ($cle['regle'] !== 'cascade' || $cle['enfant'] === $cle['parent'] || ! isset($predicats[$cle['parent']])) {
                    continue;
                }

                $clause = $this->clauseEnfant($cle, $predicats[$cle['parent']]);
                $suivants[$cle['enfant']] = isset($suivants[$cle['enfant']])
                    ? "({$suivants[$cle['enfant']]}) or ({$clause})"
                    : $clause;
            }

            if ($suivants === $predicats) {
                return $predicats;
            }

            $predicats = $suivants;
        }

        throw new RuntimeException('Graphe des clés étrangères sans point fixe : cascade circulaire à examiner avant toute purge.');
    }

    /**
     * @param  array{enfant: string, colonne: string, parent: string, cible: string, regle: string}  $cle
     */
    private function clauseEnfant(array $cle, string $predicatParent): string
    {
        return sprintf('"%s" in (select "%s" from %s where %s)', $cle['colonne'], $cle['cible'], $cle['parent'], $predicatParent);
    }

    /**
     * Ce que l'effacement du compte ferait, sans rien écrire : lignes
     * effacées et anonymisées par table, et ce qui le retient.
     *
     * @param  list<array{enfant: string, colonne: string, parent: string, cible: string, regle: string}>  $cles
     * @return array{efface: array<string, int>, anonymise: array<string, int>, blocages: list<string>}
     */
    private function mesurer(ConnectionInterface $db, object $compte, array $cles): array
    {
        $perimetre = $this->perimetre($db, $compte->id, $cles);
        $efface = [];
        $anonymise = [];
        $blocages = [];

        foreach ($perimetre as $table => $predicat) {
            $nombre = $this->compter($db, $table, $predicat);

            if ($nombre === 0) {
                continue;
            }

            $efface[$table] = $nombre;

            if (in_array($table, self::CONSERVEES, true)) {
                $blocages[] = "{$table} : {$nombre} ligne(s) de paiement que la cascade effacerait (migration du 24/09/2026 absente ?)";
            }
        }

        foreach ($this->liensSansCle($db, $compte) as $table => $requete) {
            $nombre = $requete->count();

            if ($nombre > 0) {
                $efface[$table] = ($efface[$table] ?? 0) + $nombre;
            }
        }

        foreach ($cles as $cle) {
            if ($cle['regle'] === 'cascade' || ! isset($perimetre[$cle['parent']])) {
                continue;
            }

            $predicat = $this->clauseEnfant($cle, $perimetre[$cle['parent']]);
            if (isset($perimetre[$cle['enfant']])) {
                // Une ligne déjà emportée par une autre cascade n'est pas « anonymisée ».
                $predicat = "({$predicat}) and not ({$perimetre[$cle['enfant']]})";
            }

            $nombre = $this->compter($db, $cle['enfant'], $predicat);

            if ($nombre === 0) {
                continue;
            }

            if ($cle['regle'] === 'restrict') {
                $blocages[] = "{$cle['enfant']}.{$cle['colonne']} : {$nombre} ligne(s) retiennent le compte (clé RESTRICT)";
            } else {
                $anonymise["{$cle['enfant']}.{$cle['colonne']}"] = $nombre;
            }
        }

        // Une ligne à la fois objet et auteur (le compte modifiant ses propres
        // réglages) ne compte qu'une fois : on annonce des lignes, pas des gestes.
        [$objetsEffaces, $liaisons] = $this->objetsEffacesDansLeJournal($perimetre);
        $audits = $db->table($this->tableAudits())
            ->where(fn (Builder $requete) => $requete
                ->where('user_id', $compte->id)
                ->orWhereRaw($objetsEffaces, $liaisons))
            ->count();

        if ($audits > 0) {
            $anonymise['audits'] = $audits;
        }

        if ($db->getSchemaBuilder()->hasTable('subscriptions')
            && $db->table('subscriptions')->where('user_id', $compte->id)->exists()) {
            $blocages[] = 'abonnement Stripe (subscriptions) : à résilier chez Stripe avant la purge';
        }

        return ['efface' => $efface, 'anonymise' => $anonymise, 'blocages' => $blocages];
    }

    private function compter(ConnectionInterface $db, string $table, string $predicat): int
    {
        return (int) $db->selectOne("select count(*) as n from {$table} where {$predicat}")->n;
    }

    /**
     * Liens vers le compte que le schéma ne porte par aucune clé étrangère
     * (relations polymorphes, tables clées par e-mail) : Postgres ne peut
     * pas les suivre, on les efface à la main.
     *
     * @return array<string, Builder>
     */
    private function liensSansCle(ConnectionInterface $db, object $compte): array
    {
        $morph = (new User)->getMorphClass();
        $cleMorph = (string) config('permission.column_names.model_morph_key', 'model_id');
        $email = mb_strtolower((string) $compte->email);

        $liens = [
            (string) config('permission.table_names.model_has_roles', 'model_has_roles') => fn (Builder $q) => $q->where('model_type', $morph)->where($cleMorph, $compte->id),
            (string) config('permission.table_names.model_has_permissions', 'model_has_permissions') => fn (Builder $q) => $q->where('model_type', $morph)->where($cleMorph, $compte->id),
            'personal_access_tokens' => fn (Builder $q) => $q->where('tokenable_type', $morph)->where('tokenable_id', $compte->id),
            'taggables' => fn (Builder $q) => $q->where('taggable_type', $morph)->where('taggable_id', $compte->id),
            'sessions' => fn (Builder $q) => $q->where('user_id', $compte->id),
            'password_reset_tokens' => fn (Builder $q) => $q->whereRaw('lower(email) = ?', [$email]),
            // L'invitation reçue porte l'adresse de la personne effacée ; celle
            // qu'elle a envoyée (`invited_by`) est anonymisée par sa clé.
            'user_invitations' => fn (Builder $q) => $q->whereRaw('lower(email) = ?', [$email]),
        ];

        $requetes = [];
        foreach ($liens as $table => $filtre) {
            if ($db->getSchemaBuilder()->hasTable($table)) {
                $requetes[$table] = $filtre($db->table($table));
            }
        }

        return $requetes;
    }

    /**
     * Condition SQL qui désigne, dans `audits`, les lignes dont l'objet part
     * avec le compte : le compte lui-même, ses réglages, ses dossiers.
     *
     * @param  array<string, string>  $perimetre
     * @return array{0: string, 1: list<string>} [condition, liaisons]
     */
    private function objetsEffacesDansLeJournal(array $perimetre): array
    {
        $conditions = [];
        $liaisons = [];

        foreach (self::AUDITS_A_EFFACER as $table => $modele) {
            if (isset($perimetre[$table])) {
                $conditions[] = "(auditable_type = ? and auditable_id in (select id::text from {$table} where {$perimetre[$table]}))";
                $liaisons[] = (new $modele)->getMorphClass();
            }
        }

        // `users` est toujours dans le périmètre ; une liste vide ne doit
        // pourtant jamais devenir « toutes les lignes ».
        return [$conditions === [] ? 'false' : implode(' or ', $conditions), $liaisons];
    }

    private function tableAudits(): string
    {
        return (string) config('audit.drivers.database.table', 'audits');
    }

    /**
     * Efface un compte dans une transaction : tout ou rien.
     *
     * @param  list<array{enfant: string, colonne: string, parent: string, cible: string, regle: string}>  $cles
     */
    private function purger(ConnectionInterface $db, object $compte, CarbonInterface $seuil, array $cles): void
    {
        $db->transaction(function () use ($db, $compte, $seuil, $cles) {
            // Verrou et re-lecture : un admin a pu restaurer le compte depuis
            // la mesure. Restauré, il n'est plus à nous.
            $encoreSupprime = $db->table('users')
                ->where('id', $compte->id)
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<', $seuil)
                ->lockForUpdate()
                ->exists();

            if (! $encoreSupprime) {
                throw new RuntimeException('compte restauré ou disparu depuis la mesure : rien effacé');
            }

            $perimetre = $this->perimetre($db, $compte->id, $cles);

            // 1. Le journal, tant que les identifiants des lignes emportées se
            //    lisent encore : on garde l'événement et la date, pas les valeurs.
            [$objetsEffaces, $liaisons] = $this->objetsEffacesDansLeJournal($perimetre);
            $db->table($this->tableAudits())->whereRaw($objetsEffaces, $liaisons)->update([
                'old_values' => DB::raw($this->valeursEffacees('old_values')),
                'new_values' => DB::raw($this->valeursEffacees('new_values')),
                'ip_address' => null,
                'user_agent' => null,
            ]);

            // L'auteur disparaît de ses actions, l'action reste.
            $db->table($this->tableAudits())->where('user_id', $compte->id)->update([
                'user_id' => null,
                'user_type' => null,
                'ip_address' => null,
                'user_agent' => null,
            ]);

            // 2. Les liens que Postgres ne suit pas.
            foreach ($this->liensSansCle($db, $compte) as $requete) {
                $requete->delete();
            }

            // 3. Le compte : chaque clé étrangère applique sa règle (CASCADE
            //    efface, SET NULL anonymise, RESTRICT lèverait et annulerait tout).
            $db->table('users')->where('id', $compte->id)->delete();
        });
    }

    /**
     * Garde les clés d'un objet JSON (quels champs ont changé), remplace ses
     * valeurs par null ; laisse tel quel un tableau vide ou une valeur nulle.
     */
    private function valeursEffacees(string $colonne): string
    {
        return <<<SQL
            case when {$colonne} is not null and jsonb_typeof({$colonne}::jsonb) = 'object'
                 then coalesce((select jsonb_object_agg(cle, 'null'::jsonb) from jsonb_object_keys({$colonne}::jsonb) as cle), '{}'::jsonb)::text
                 else {$colonne}
            end
            SQL;
    }

    /**
     * @param  list<object>  $comptes
     * @param  array<string, array{efface: array<string, int>, anonymise: array<string, int>, blocages: list<string>}>  $mesures
     */
    private function afficherMesures(array $comptes, array $mesures): void
    {
        $this->table(
            ['Compte', 'Supprimé le', 'Lignes effacées', 'Lignes anonymisées', 'Décision'],
            array_map(fn (object $c) => [
                $this->libelle($c),
                substr((string) $c->deleted_at, 0, 10),
                array_sum($mesures[$c->id]['efface']),
                array_sum($mesures[$c->id]['anonymise']),
                $mesures[$c->id]['blocages'] === [] ? 'à effacer' : 'sauté : '.implode(' ; ', $mesures[$c->id]['blocages']),
            ], $comptes),
        );

        foreach (['efface' => 'Effacé', 'anonymise' => 'Anonymisé'] as $cle => $titre) {
            $totaux = [];
            foreach ($comptes as $compte) {
                if ($mesures[$compte->id]['blocages'] !== []) {
                    continue;
                }

                foreach ($mesures[$compte->id][$cle] as $table => $nombre) {
                    $totaux[$table] = ($totaux[$table] ?? 0) + $nombre;
                }
            }

            if ($totaux === []) {
                continue;
            }

            ksort($totaux);
            $this->newLine();
            $this->line("<options=bold>{$titre}</>");
            foreach ($totaux as $table => $nombre) {
                $this->line("  {$table} : {$nombre}");
            }
        }
    }

    /** Huit premiers caractères de l'UUID : assez pour retrouver le compte, rien de personnel. */
    private function libelle(object $compte): string
    {
        return substr((string) $compte->id, 0, 8);
    }
}
