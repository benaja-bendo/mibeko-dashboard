<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Corrige le titre affiché de documents déjà PUBLIÉS, en passant par l'API
 * Laravel — jamais en SQL, jamais en touchant au slug.
 *
 * `mibeko:corriger-titres-jo` fait le symétrique pour des documents en
 * brouillon (JO mal découpés) et ignore volontairement tout document publié
 * — changer le SLUG d'un document déjà publié casserait son URL indexée par
 * Google et partagée. Cette commande ne modifie QUE `titre_officiel`
 * (le H1 affiché, le fil d'Ariane, le `<title>` SEO) : le slug ne bouge pas.
 * C'est un correctif partiel assumé — l'URL reste imparfaite tant qu'un
 * mécanisme de redirection ancien-slug → nouveau-slug n'existe pas.
 *
 * Consomme directement le fichier produit par `mibeko:proposer-titres`
 * (tableau de {id, titre, ...}, les champs en trop sont ignorés).
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:corriger-titres-publies --liste=titres-proposes-*.json          # simulation
 *   php artisan mibeko:corriger-titres-publies --liste=titres-proposes-*.json --execute
 */
class CorrigerTitresPubliesCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:corriger-titres-publies
        {--liste= : Fichier JSON [{id, titre, …}, …] — celui produit par mibeko:proposer-titres convient tel quel}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--rythme=40 : Documents par minute (quota API 60 req/min, 1 appel par document ici)}
        {--echecs= : Fichier où écrire les documents non corrigés, au format --liste, pour relancer}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Corrige le titre_officiel de documents publiés via l\'API (jamais le slug).';

    public function handle(): int
    {
        $chemin = (string) $this->option('liste');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --liste obligatoire : chemin d\'un fichier JSON lisible.');

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

        $lot = [];
        foreach ($entrees as $entree) {
            $id = (string) ($entree['id'] ?? '');
            $titre = trim((string) ($entree['titre'] ?? ''));

            if ($id === '' || $titre === '') {
                continue;
            }

            $lot[] = ['id' => $id, 'titre' => $titre, 'titre_actuel' => $entree['titre_actuel'] ?? null];
        }

        if ($lot === []) {
            $this->error('Aucune entrée exploitable (id + titre requis) dans le fichier.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $rythme = max(0, (int) $this->option('rythme'));
        $total = count($lot);

        if (! $execute) {
            $this->info("{$total} document(s) publié(s) verraient leur titre corrigé sur {$baseUrl}.");
            $this->line('Un seul appel par document : PATCH titre_officiel. Le slug ne change pas.');
            $this->newLine();

            foreach ($lot as $entree) {
                $this->line(sprintf('  · %s', $entree['id']));
                $this->line(sprintf('     avant : %s', Str::limit((string) ($entree['titre_actuel'] ?? '(inconnu)'), 70)));
                $this->line(sprintf('     après : %s', Str::limit($entree['titre'], 70)));
            }

            $this->newLine();
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour corriger.');

            return self::SUCCESS;
        }

        $corriges = 0;
        $echecs = [];
        $arendre = [];
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;
        $rang = 0;

        foreach ($lot as $entree) {
            $rang++;
            $debut = microtime(true);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            $reponse = $this->patcher($jeton, "{$baseUrl}/legal-documents/{$entree['id']}", [
                'titre_officiel' => $entree['titre'],
            ]);

            if ($reponse === null || $reponse->failed()) {
                $echecs[] = [Str::limit($entree['titre'], 60), $this->motif($reponse)];
                $arendre[] = $entree;
                $this->line("<fg=red>✗</> {$avancement} {$entree['id']} — correction refusée");
                $this->tenirLaCadence($intervalle, $debut, $rang < $total);

                continue;
            }

            $corriges++;
            $this->line("<fg=green>✓</> {$avancement} ".Str::limit($entree['titre'], 70));
            $this->tenirLaCadence($intervalle, $debut, $rang < $total);
        }

        $this->newLine();
        $this->info("{$corriges} document(s) corrigé(s) sur {$total}.");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Titre visé', 'Motif'], $echecs);
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
        $this->line("Reprise : <fg=cyan>php artisan mibeko:corriger-titres-publies --liste={$chemin} --execute</>");
    }

    private function motif(?Response $reponse): string
    {
        if ($reponse === null) {
            return 'réseau injoignable après '.self::TENTATIVES_MAX.' reprises';
        }

        $corps = $reponse->json();
        $erreurs = data_get($corps, 'errors', []);
        $premiere = is_array($erreurs) ? (data_get($erreurs, '*.0')[0] ?? null) : null;

        return Str::limit((string) ($premiere ?: data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
