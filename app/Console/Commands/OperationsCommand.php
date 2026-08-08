<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\EcartEffectifException;
use App\Services\Operations\OperationsClasseUne;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * File d'opérations Classe 1 — le worker du terminal HUMAIN
 * (docs/infra/production.md § 6 bis, décision du 08/08/2026).
 *
 * L'agent prépare des lots JSON dans `storage/app/operations/pending/`
 * (gitignoré, jamais de credential dedans) ; ce worker les présente un par
 * un — cible, effet, effectif annoncé, retour arrière — et attend `y` ou `n`
 * au clavier. `y` exécute par les canaux applicatifs (Eloquent : machine à
 * états, audit owen-it, policy documents.update) dans une transaction ; `n`
 * archive le lot dans `rejected/`. Si l'effectif touché dévie de
 * `expected_rows`, la transaction est annulée, le lot reste en place et la
 * file s'arrête net : un écart d'effectif est un incident, pas un
 * avertissement.
 *
 * La connexion visée est celle du shell de l'humain. En développement (et
 * pour tester le circuit), la connexion par défaut de l'appli suffit :
 *
 *   export MIBEKO_API_TOKEN='…'        # jeton Sanctum d'un éditeur/admin
 *   php artisan mibeko:operations --watch
 *
 * En production, les credentials s'exportent à la main (jamais dans un
 * fichier, `.env` compris), tunnel ouvert sur 5434 :
 *
 *   export PROD_RW_DB_HOST=127.0.0.1 PROD_RW_DB_PORT=5434 PROD_RW_DB_DATABASE=… \
 *          PROD_RW_DB_USERNAME=… PROD_RW_DB_PASSWORD=… MIBEKO_API_TOKEN=…
 *   php artisan mibeko:operations --watch --connection=pgsql_prod_rw
 *   unset PROD_RW_DB_HOST PROD_RW_DB_PORT PROD_RW_DB_DATABASE \
 *         PROD_RW_DB_USERNAME PROD_RW_DB_PASSWORD MIBEKO_API_TOKEN
 *
 * Le cadre de session du § 6 demeure : dump frais avant la première
 * écriture, compteurs avant/après consignés par campagne. Ce worker ne
 * remplace que l'autorisation par opération dans le chat — par une
 * validation par lot, dans le terminal.
 */
class OperationsCommand extends Command
{
    protected $signature = 'mibeko:operations
        {--watch : Surveille la file en continu (Ctrl+C pour arrêter)}
        {--interval=5 : Secondes entre deux balayages en mode --watch}
        {--connection= : Connexion cible (défaut : celle de l\'appli ; pgsql_prod_rw pour la production)}';

    protected $description = 'File d\'opérations Classe 1 : présente les lots de curation de staging et les exécute après validation y/n (production.md § 6 bis).';

    public function handle(OperationsClasseUne $operations): int
    {
        // Les lots se valident au clavier : sans TTY (cron, agent), confirm()
        // répondrait « n » à tout et viderait la file vers rejected/. Les
        // tests console simulent le clavier (expectsConfirmation), d'où
        // l'exception.
        if (! $this->input->isInteractive() && ! $this->laravel->runningUnitTests()) {
            $this->error('La file d\'opérations exige un terminal interactif : chaque lot se valide au clavier (y/n).');

            return self::FAILURE;
        }

        if (! $this->basculerConnexion()) {
            return self::FAILURE;
        }

        $utilisateur = $this->utilisateurDuJeton();

        if ($utilisateur === null) {
            return self::FAILURE;
        }

        // L'attribution passe par le garde d'authentification : owen-it
        // résout l'auteur des audits via Auth, comme pour une requête API.
        Auth::setUser($utilisateur);

        foreach (['pending', 'done', 'rejected'] as $etat) {
            File::ensureDirectoryExists($this->chemin($etat));
        }

        $this->banniere($utilisateur);

        $intervalle = max(1, (int) $this->option('interval'));
        $attenteAffichee = false;

        while (true) {
            $lots = glob($this->chemin('pending').'/*.json') ?: [];
            sort($lots);

            if ($lots !== []) {
                $attenteAffichee = false;
            }

            foreach ($lots as $cheminLot) {
                if (! $this->traiterLot($cheminLot, $operations, $utilisateur)) {
                    return self::FAILURE;
                }
            }

            if (! $this->option('watch')) {
                if ($lots === []) {
                    $this->line('Aucun lot en attente.');
                }

                return self::SUCCESS;
            }

            if ($lots === [] && ! $attenteAffichee) {
                $this->line('File vide — en attente de lots (Ctrl+C pour arrêter).');
                $attenteAffichee = true;
            }

            Sleep::for($intervalle)->seconds();
        }
    }

    /**
     * Traite un lot : rejet motivé (liste blanche), validation y/n, exécution
     * en transaction. Renvoie false quand la file doit s'arrêter net
     * (incident) — le lot reste alors dans pending/.
     */
    private function traiterLot(string $cheminLot, OperationsClasseUne $operations, User $utilisateur): bool
    {
        $nom = basename($cheminLot);
        $lot = json_decode(File::get($cheminLot), true);

        if (! is_array($lot)) {
            $this->error("✗ {$nom} — JSON illisible, lot déplacé vers rejected/.");
            $this->rejeter($cheminLot, null, []);

            return true;
        }

        $violations = $operations->violations($lot, $utilisateur);

        if ($violations !== []) {
            $this->error("✗ {$nom} — lot rejeté :");

            foreach ($violations as $violation) {
                $this->line("    · {$violation}");
            }

            $this->rejeter($cheminLot, $lot, $violations);

            return true;
        }

        $this->afficherLot($nom, $lot, $operations);

        if (! $this->confirm('Exécuter ce lot ?')) {
            $this->rejeter($cheminLot, $lot, ['Refusé par l\'opérateur (n).']);
            $this->line("Lot {$nom} déplacé vers rejected/.");

            return true;
        }

        $annoncees = (int) $lot['expected_rows'];

        try {
            $touchees = DB::transaction(function () use ($operations, $lot, $utilisateur, $annoncees): int {
                $touchees = $operations->executer($lot, $utilisateur);

                if ($touchees !== $annoncees) {
                    throw new EcartEffectifException($touchees, $annoncees);
                }

                return $touchees;
            });
        } catch (Throwable $exception) {
            $this->incident($nom, $exception);

            return false;
        }

        $this->archiver($cheminLot, $lot, $touchees, $utilisateur);
        $this->info("✓ {$nom} — {$touchees} ligne(s) touchée(s), lot archivé dans done/.");

        return true;
    }

    /**
     * Écran de validation : tout ce que le § 6 bis impose d'afficher avant le
     * y/n — cible, effet, effectif annoncé, retour arrière — plus le dry-run
     * consigné, qui est la preuve de préparation du lot.
     *
     * @param  array<string, mixed>  $lot
     */
    private function afficherLot(string $nom, array $lot, OperationsClasseUne $operations): void
    {
        ['cible' => $cible, 'effet' => $effet] = $operations->decrire($lot);

        $this->newLine();
        $this->line("<options=bold>── Lot {$nom}</> — campagne « {$lot['campagne']} »");
        $this->line("  Opération        : {$lot['operation']}");
        $this->line("  Cible            : {$cible}");
        $this->line("  Effet            : {$effet}");
        $this->line("  Lignes attendues : {$lot['expected_rows']}");
        $this->line('  Retour arrière   : '.$lot['rollback']['description']);
        $this->line('                     Moyen : '.$lot['rollback']['moyen']);
        $this->line('  Dry-run consigné :');

        foreach ($this->lignesDryRun($lot['dry_run_output']) as $ligne) {
            $this->line('    '.$ligne);
        }
    }

    /**
     * @return list<string>
     */
    private function lignesDryRun(string $sortie): array
    {
        $lignes = preg_split('/\R/', trim($sortie)) ?: [];

        if (count($lignes) > 20) {
            $reste = count($lignes) - 20;
            $lignes = [...array_slice($lignes, 0, 20), "… ({$reste} ligne(s) de plus dans le fichier du lot)"];
        }

        return $lignes;
    }

    private function incident(string $nom, Throwable $exception): void
    {
        $motif = $exception instanceof EcartEffectifException
            ? $exception->getMessage()
            : get_class($exception).' — '.$exception->getMessage();

        $this->newLine();
        $this->error("INCIDENT — lot {$nom} : {$motif}");
        $this->error('Transaction annulée : aucune écriture de ce lot n\'est conservée.');
        $this->error('Le lot reste dans pending/ et la file s\'arrête. Expliquer l\'écart avant toute relance (production.md § 6 bis).');
    }

    /**
     * Pointe le processus sur la connexion demandée. Contrairement à un
     * `.env` basculé (interdit absolu : il redirigerait scheduler, jobs et
     * serveurs MCP), ce réglage ne vit que le temps de cette commande — et
     * il embarque tout uniformément : modèles, écritures d'audit, résolution
     * du jeton Sanctum.
     */
    private function basculerConnexion(): bool
    {
        $nom = (string) ($this->option('connection') ?: config('database.default'));

        if ($nom === 'pgsql_prod_ro') {
            $this->error('pgsql_prod_ro est un profil de LECTURE : la file écrit, elle exige pgsql_prod_rw (ou la connexion par défaut en développement).');

            return false;
        }

        $config = config("database.connections.{$nom}");

        if (! is_array($config)) {
            $this->error("La connexion « {$nom} » n'est pas déclarée dans config/database.php.");

            return false;
        }

        if (empty($config['host']) || empty($config['database'])) {
            $this->error("Connexion « {$nom} » incomplète : variables absentes du shell.");
            $this->line('Pour la production : export PROD_RW_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD — à la main, jamais dans un fichier.');

            return false;
        }

        config(['database.default' => $nom]);

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            $this->error("Connexion « {$nom} » injoignable : ".$exception->getMessage());
            $this->line('Tunnel ouvert ? (ssh -N -L 5434:127.0.0.1:5432 ubuntu@<IP_VPS>)');

            return false;
        }

        return true;
    }

    /**
     * Résout l'opérateur depuis MIBEKO_API_TOKEN (jeton Sanctum exporté à la
     * main dans le shell, comme pour publier-vague) : c'est lui que portent
     * `resolved_by`, les audits et le rapport de chaque lot.
     */
    private function utilisateurDuJeton(): ?User
    {
        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell : à exporter à la main (jamais dans un fichier), comme pour publier-vague.');

            return null;
        }

        $utilisateur = PersonalAccessToken::findToken($jeton)?->tokenable;

        // Un compte soft-supprimé ne résout plus (portée SoftDeletes) : le
        // jeton d'un ex-éditeur ne doit pas continuer à autoriser des lots.
        if (! $utilisateur instanceof User) {
            $this->error('MIBEKO_API_TOKEN ne correspond à aucun utilisateur actif sur la connexion visée.');

            return null;
        }

        return $utilisateur;
    }

    private function banniere(User $utilisateur): void
    {
        $config = DB::connection()->getConfig();

        $this->info('File d\'opérations Classe 1 (production.md § 6 bis)');
        $this->line(sprintf(
            '  Connexion : %s — %s:%s/%s',
            config('database.default'),
            $config['host'] ?? '?',
            $config['port'] ?? '?',
            $config['database'] ?? '?',
        ));
        $this->line(sprintf('  Opérateur : %s <%s>', $utilisateur->name, $utilisateur->email));
        $this->line('  File      : '.$this->chemin('pending'));
    }

    /**
     * Archive un lot refusé dans rejected/, motifs consignés dans le fichier
     * (un JSON illisible est déplacé tel quel).
     *
     * @param  array<string, mixed>|null  $lot
     * @param  list<string>  $motifs
     */
    private function rejeter(string $cheminLot, ?array $lot, array $motifs): void
    {
        $destination = $this->chemin('rejected').'/'.$this->horodate(basename($cheminLot));

        if ($lot === null) {
            File::move($cheminLot, $destination);

            return;
        }

        File::put($destination, $this->json([...$lot, 'rejet' => [
            'le' => now()->toIso8601String(),
            'motifs' => $motifs,
        ]]));
        File::delete($cheminLot);
    }

    /**
     * Archive un lot exécuté dans done/ avec son rapport : horodatage,
     * effectif réellement touché, utilisateur du jeton, connexion visée.
     *
     * @param  array<string, mixed>  $lot
     */
    private function archiver(string $cheminLot, array $lot, int $touchees, User $utilisateur): void
    {
        $destination = $this->chemin('done').'/'.$this->horodate(basename($cheminLot));

        File::put($destination, $this->json([...$lot, 'rapport' => [
            'execute_le' => now()->toIso8601String(),
            'lignes_touchees' => $touchees,
            'utilisateur' => [
                'id' => $utilisateur->id,
                'name' => $utilisateur->name,
                'email' => $utilisateur->email,
            ],
            'connexion' => (string) config('database.default'),
        ]]));
        File::delete($cheminLot);
    }

    /** Préfixe horodaté : évite d'écraser un homonyme déjà archivé. */
    private function horodate(string $nom): string
    {
        return now()->format('Ymd-His').'_'.$nom;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function json(array $donnees): string
    {
        return (string) json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function chemin(string $etat): string
    {
        return storage_path('app/operations/'.$etat);
    }
}
