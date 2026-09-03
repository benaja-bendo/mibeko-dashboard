<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Crée des relations entre documents (abroge, crée, modifie, complète, cite,
 * renumérote) via `POST /document-relations` — jamais en SQL direct.
 *
 * `document_relations` n'a pas de contrainte d'unicité sur
 * (source, target, type) : rejouer ce mapping après un premier `--execute`
 * dupliquerait les lignes. Utiliser `--echecs` pour ne relancer que les
 * entrées réellement en échec, pas le fichier complet.
 *
 * Consomme le fichier produit par `mibeko:proposer-statuts-constitutionnels`
 * (tableau de {source_doc_id, target_doc_id, relation_type, …}).
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:creer-relations-documents --mapping=relations.json          # simulation
 *   php artisan mibeko:creer-relations-documents --mapping=relations.json --execute
 */
class CreerRelationsDocumentsCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:creer-relations-documents
        {--mapping= : Fichier JSON [{source_doc_id, target_doc_id, relation_type, effective_date, commentaire, document}, …]}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--rythme=40 : Relations par minute (quota API 60 req/min, 1 appel par relation ici)}
        {--echecs= : Fichier où écrire les relations non créées, au format --mapping, pour relancer}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Crée des relations entre documents (abroge, crée, modifie…) via l\'API.';

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
                Str::limit((string) ($e['document'] ?? ''), 55),
                (string) ($e['relation_type'] ?? ''),
                (string) ($e['effective_date'] ?? ''),
            ];
        }
        $this->table(['Relation', 'Type', 'Date d\'effet'], $lignes);

        if (! $execute) {
            $this->newLine();
            $this->info(count($entrees)." relation(s) seraient créées sur {$baseUrl}.");
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour créer.');

            return self::SUCCESS;
        }

        $rythme = max(0, (int) $this->option('rythme'));
        $total = count($entrees);
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;

        $creees = 0;
        $echecs = [];
        $arendre = [];
        $rang = 0;

        foreach ($entrees as $e) {
            $rang++;
            $debut = microtime(true);
            $label = (string) ($e['document'] ?? '');
            $avancement = sprintf('[%d/%d]', $rang, $total);

            $charge = array_filter([
                'source_doc_id' => $e['source_doc_id'] ?? null,
                'target_doc_id' => $e['target_doc_id'] ?? null,
                'relation_type' => $e['relation_type'] ?? null,
                'effective_date' => $e['effective_date'] ?? null,
                'commentaire' => $e['commentaire'] ?? null,
            ], fn ($v) => $v !== null);

            if (! isset($charge['source_doc_id'], $charge['target_doc_id'], $charge['relation_type'])) {
                $echecs[] = [Str::limit($label, 50), 'source, cible ou type manquant'];
                $this->line("<fg=red>✗</> {$avancement} {$label}");

                continue;
            }

            $reponse = $this->poster($jeton, "{$baseUrl}/document-relations", $charge);

            if ($reponse === null || $reponse->failed()) {
                $echecs[] = [Str::limit($label, 50), $this->motif($reponse)];
                $arendre[] = $e;
                $this->line("<fg=red>✗</> {$avancement} {$label}");
                $this->tenirLaCadence($intervalle, $debut, $rang < $total);

                continue;
            }

            $creees++;
            $this->line("<fg=green>✓</> {$avancement} {$label}");
            $this->tenirLaCadence($intervalle, $debut, $rang < $total);
        }

        $this->newLine();
        $this->info("{$creees} relation(s) créée(s) sur {$total}.");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Relation', 'Motif'], $echecs);
            $this->warn(count($echecs).' relation(s) non créée(s).');
            $this->ecrireLesEchecs($arendre);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function poster(string $jeton, string $url, array $charge): ?Response
    {
        for ($tentative = 1; ; $tentative++) {
            try {
                $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)->post($url, $charge);
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

    private function tenirLaCadence(float $intervalle, float $debut, bool $encoreDesRelations): void
    {
        if ($intervalle <= 0 || ! $encoreDesRelations) {
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
        $this->line("Reprise : <fg=cyan>php artisan mibeko:creer-relations-documents --mapping={$chemin} --execute</>");
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
