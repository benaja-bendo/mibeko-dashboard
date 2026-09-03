<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Rattrape les comptes créés sans `status` (colonne NULL), invisibles à tout
 * filtre `status = 'active'` — quota, veille, statistiques.
 *
 * Lecture et écriture sont séparées : le diagnostic interroge une connexion de
 * lecture seule (`pgsql_prod_ro` par défaut), l'écriture passe par
 * `PATCH /admin/users/{id}` — le canal API, parce qu'il est audité (owen-it) et
 * qu'il applique les mêmes garde-fous que la console d'administration. Aucun
 * SQL d'écriture ici.
 *
 *   php artisan mibeko:prod-preflight
 *   php artisan mibeko:corriger-statut-comptes                        # simulation
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:corriger-statut-comptes --limit=5 --execute    # lot pilote
 *   php artisan mibeko:corriger-statut-comptes --execute
 *   unset MIBEKO_API_TOKEN
 */
class CorrigerStatutComptesCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    /** Mêmes valeurs que `UpdateUserRequest` : la commande n'en invente pas. */
    private const STATUTS = ['active', 'suspended', 'pending'];

    protected $signature = 'mibeko:corriger-statut-comptes
        {--connection=pgsql_prod_ro : Connexion de LECTURE (le diagnostic ; l\'écriture passe par l\'API)}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--statut=active : Statut posé sur les comptes qui n\'en ont aucun}
        {--limit=0 : Ne traiter que les N premiers comptes (lot pilote)}
        {--rythme=40 : Comptes par minute (quota API 60 req/min, 1 appel par compte)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Renseigne le statut des comptes restés à NULL, via l\'API d\'administration.';

    public function handle(): int
    {
        $statut = (string) $this->option('statut');

        if (! in_array($statut, self::STATUTS, true)) {
            $this->error('--statut doit valoir '.implode(', ', self::STATUTS).'.');

            return self::FAILURE;
        }

        $connexion = (string) $this->option('connection');
        $execute = (bool) $this->option('execute');
        $baseUrl = rtrim((string) $this->option('base-url'), '/');

        $comptes = $this->comptesSansStatut($connexion);
        $total = count($comptes);

        $this->info("Comptes vivants sans statut sur « {$connexion} » : {$total}.");

        if ($total === 0) {
            return self::SUCCESS;
        }

        $limite = max(0, (int) $this->option('limit'));
        if ($limite > 0) {
            $comptes = array_slice($comptes, 0, $limite);
        }
        $aTraiter = count($comptes);

        $this->table(
            ['Compte', 'Adresse (masquée)', 'Créé le'],
            array_map(fn (object $c) => [
                Str::limit((string) $c->id, 12),
                $this->masquer((string) $c->email),
                (string) $c->created_at,
            ], $comptes),
        );

        if (! $execute) {
            $this->newLine();
            $this->info("{$aTraiter} compte(s) passeraient à « {$statut} » sur {$baseUrl}.");
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour écrire.');

            return self::SUCCESS;
        }

        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        $rythme = max(0, (int) $this->option('rythme'));
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;

        $corriges = 0;
        $echecs = [];
        $rang = 0;

        foreach ($comptes as $compte) {
            $rang++;
            $debut = microtime(true);
            $label = $this->masquer((string) $compte->email);
            $avancement = sprintf('[%d/%d]', $rang, $aTraiter);

            $reponse = $this->patcher($jeton, "{$baseUrl}/admin/users/{$compte->id}", ['status' => $statut]);

            if ($reponse === null || $reponse->failed()) {
                $echecs[] = [$label, $this->motif($reponse)];
                $this->line("<fg=red>✗</> {$avancement} {$label}");
            } else {
                $corriges++;
                $this->line("<fg=green>✓</> {$avancement} {$label}");
            }

            $this->tenirLaCadence($intervalle, $debut, $rang < $aTraiter);
        }

        $this->newLine();
        $this->info("{$corriges} compte(s) corrigé(s) sur {$aTraiter}.");

        if ($echecs !== []) {
            $this->table(['Adresse (masquée)', 'Motif'], $echecs);
            $this->warn(count($echecs).' compte(s) non corrigé(s) — relancer la commande les reprendra.');
        }

        // Mesure d'après, sur la connexion de lecture : l'écart avec le compte
        // d'avant doit valoir exactement le nombre de corrections annoncées.
        $reste = count($this->comptesSansStatut($connexion));
        $this->info("Comptes vivants sans statut après passage : {$reste} (avant : {$total}).");

        return $echecs === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<object>
     */
    private function comptesSansStatut(string $connexion): array
    {
        return DB::connection($connexion)
            ->table('users')
            ->whereNull('status')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get(['id', 'email', 'created_at'])
            ->all();
    }

    /** Le terminal de l'humain n'a pas besoin de l'adresse complète. */
    private function masquer(string $email): string
    {
        [$local, $domaine] = array_pad(explode('@', $email, 2), 2, '');

        return $local === '' ? '(sans adresse)' : mb_substr($local, 0, 1).'***@'.$domaine;
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function patcher(string $jeton, string $url, array $charge): ?Response
    {
        for ($tentative = 1; ; $tentative++) {
            try {
                $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)->patch($url, $charge);
            } catch (ConnectionException $e) {
                if ($tentative > self::TENTATIVES_MAX) {
                    $this->line('  <fg=red>réseau injoignable</> — '.Str::limit($e->getMessage(), 60));

                    return null;
                }

                $this->attendre($this->backoff($tentative), 'réseau injoignable', $tentative);

                continue;
            }

            if (! $this->estTemporaire($reponse) || $tentative > self::TENTATIVES_MAX) {
                return $reponse;
            }

            $this->attendre(
                $this->delaiDemande($reponse) ?? $this->backoff($tentative),
                (string) $reponse->status(),
                $tentative,
            );
        }
    }

    private function estTemporaire(Response $reponse): bool
    {
        return $reponse->status() === 429 || $reponse->serverError();
    }

    private function delaiDemande(Response $reponse): ?int
    {
        $entete = $reponse->header('Retry-After');

        return is_numeric($entete) ? min((int) $entete, self::ATTENTE_MAX_SECONDES) : null;
    }

    private function backoff(int $tentative): int
    {
        return (int) min(2 ** $tentative, self::ATTENTE_MAX_SECONDES);
    }

    private function attendre(int $secondes, string $motif, int $tentative): void
    {
        $this->line(sprintf(
            '  <fg=yellow>⟳</> %s — nouvelle tentative dans %d s (%d/%d)',
            $motif, $secondes, $tentative, self::TENTATIVES_MAX,
        ));

        Sleep::for($secondes)->seconds();
    }

    private function tenirLaCadence(float $intervalle, float $debut, bool $encoreDesComptes): void
    {
        if ($intervalle <= 0 || ! $encoreDesComptes) {
            return;
        }

        $reste = $intervalle - (microtime(true) - $debut);

        if ($reste > 0) {
            Sleep::for((int) round($reste * 1000))->milliseconds();
        }
    }

    private function motif(?Response $reponse): string
    {
        if ($reponse === null) {
            return 'réseau injoignable après '.self::TENTATIVES_MAX.' reprises';
        }

        return Str::limit((string) (data_get($reponse->json(), 'message') ?: $reponse->status()), 60);
    }
}
