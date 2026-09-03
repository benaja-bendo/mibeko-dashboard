<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Corrige le `statut` (vigueur/abroge/projet) de documents nommément listés,
 * via `PATCH /legal-documents/{id}` — jamais en SQL direct.
 *
 * Ce canal pose automatiquement `statut_verifie_le`/`statut_verifie_par`
 * (`LegalDocumentController::update`) : la vérification humaine est donc
 * tracée par construction, pas ajoutée séparément.
 *
 * Consomme le fichier produit par `mibeko:proposer-statuts-constitutionnels`
 * (tableau de {id, statut, …}, les champs en trop sont ignorés).
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:corriger-statut-document --mapping=statuts.json          # simulation
 *   php artisan mibeko:corriger-statut-document --mapping=statuts.json --execute
 */
class CorrigerStatutDocumentCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:corriger-statut-document
        {--mapping= : Fichier JSON [{id, document, motif, statut}, …]}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--rythme=40 : Documents par minute (quota API 60 req/min, 1 appel par document ici)}
        {--echecs= : Fichier où écrire les documents non corrigés, au format --mapping, pour relancer}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = "Corrige le statut juridique (vigueur/abroge/projet) de documents publiés via l'API.";

    public function handle(): int
    {
        $chemin = (string) $this->option('mapping');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --mapping obligatoire : chemin d\'un fichier JSON lisible.');

            return self::FAILURE;
        }

        $entrees = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($entrees) || $entrees === []) {
            $this->error('La liste est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($execute && $jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('base-url'), '/');

        $lignes = [];
        foreach ($entrees as $e) {
            $lignes[] = [
                Str::limit((string) ($e['document'] ?? ''), 40),
                (string) ($e['statut'] ?? ''),
                Str::limit((string) ($e['motif'] ?? ''), 40),
            ];
        }
        $this->table(['Document', 'Nouveau statut', 'Motif'], $lignes);

        if (! $execute) {
            $this->newLine();
            $this->info(count($entrees)." document(s) seraient corrigés sur {$baseUrl}.");
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour corriger.');

            return self::SUCCESS;
        }

        $rythme = max(0, (int) $this->option('rythme'));
        $total = count($entrees);
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;

        $corriges = 0;
        $echecs = [];
        $arendre = [];
        $rang = 0;

        foreach ($entrees as $e) {
            $rang++;
            $debut = microtime(true);
            $id = (string) ($e['id'] ?? '');
            $statut = (string) ($e['statut'] ?? '');
            $label = (string) ($e['document'] ?? $id);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            if ($id === '' || $statut === '') {
                $echecs[] = [Str::limit($label, 50), 'id ou statut manquant'];
                $this->line("<fg=red>✗</> {$avancement} {$label}");

                continue;
            }

            $reponse = $this->patcher($jeton, "{$baseUrl}/legal-documents/{$id}", ['statut' => $statut]);

            if ($reponse === null || $reponse->failed()) {
                $echecs[] = [Str::limit($label, 50), $this->motif($reponse)];
                $arendre[] = $e;
                $this->line("<fg=red>✗</> {$avancement} {$label}");
                $this->tenirLaCadence($intervalle, $debut, $rang < $total);

                continue;
            }

            $corriges++;
            $this->line("<fg=green>✓</> {$avancement} {$label}");
            $this->tenirLaCadence($intervalle, $debut, $rang < $total);
        }

        $this->newLine();
        $this->info("{$corriges} document(s) corrigé(s) sur {$total}.");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Document', 'Motif'], $echecs);
            $this->warn(count($echecs).' document(s) non corrigé(s).');
            $this->ecrireLesEchecs($arendre);
        }

        return self::SUCCESS;
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

    private function tenirLaCadence(float $intervalle, float $debut, bool $encoreDesDocuments): void
    {
        if ($intervalle <= 0 || ! $encoreDesDocuments) {
            return;
        }

        $reste = $intervalle - (microtime(true) - $debut);

        if ($reste > 0) {
            Sleep::for((int) round($reste * 1000))->milliseconds();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $arendre
     */
    private function ecrireLesEchecs(array $arendre): void
    {
        $chemin = (string) $this->option('echecs');

        if ($chemin === '' || $arendre === []) {
            return;
        }

        file_put_contents($chemin, json_encode($arendre, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        $this->newLine();
        $this->line("Reprise : <fg=cyan>php artisan mibeko:corriger-statut-document --mapping={$chemin} --execute</>");
    }

    private function motif(?Response $reponse): string
    {
        if ($reponse === null) {
            return 'réseau injoignable après '.self::TENTATIVES_MAX.' reprises';
        }

        $corps = $reponse->json();

        return Str::limit((string) (data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
