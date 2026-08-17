<?php

namespace App\Console\Commands;

use App\Models\LegalDocument;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Applique un lot de libellés descriptifs RELUS, en passant par l'API Laravel.
 *
 * Consomme le fichier produit par `mibeko:proposer-libelles` (tableau de
 * {id, libelle, …}, les champs en trop sont ignorés) une fois qu'un humain l'a
 * relu et corrigé.
 *
 * N'écrit QUE `libelle_descriptif` et sa provenance. `titre_officiel` n'est
 * jamais dans la charge utile — c'est la règle du chantier du 16/08/2026 : le
 * libellé est dérivé du corps de l'acte, il s'affiche À CÔTÉ du titre officiel
 * et ne le remplace pas. Une entrée qui prétendrait porter un titre est
 * refusée, pas ignorée en silence.
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:appliquer-libelles --liste=libelles-proposes-*.json            # simulation
 *   php artisan mibeko:appliquer-libelles --liste=libelles-proposes-*.json --execute
 */
class AppliquerLibellesCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:appliquer-libelles
        {--liste= : Fichier JSON [{id, libelle, …}, …] — celui produit par mibeko:proposer-libelles convient tel quel}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--source=article : Provenance déclarée (article = dérivé du corps, manuel = rédigé à la main)}
        {--confiance= : Ne retenir que cette confiance (haute, a_verifier) — par défaut, tout le fichier}
        {--rythme=40 : Documents par minute (quota API 60 req/min, 1 appel par document ici)}
        {--echecs= : Fichier où écrire les documents non traités, au format --liste, pour relancer}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Applique un lot relu de libellés descriptifs via l\'API (ne touche jamais au titre).';

    public function handle(): int
    {
        $chemin = (string) $this->option('liste');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --liste obligatoire : chemin d\'un fichier JSON lisible.');

            return self::FAILURE;
        }

        $source = (string) $this->option('source');

        if (! in_array($source, LegalDocument::LIBELLE_SOURCES, true)) {
            $this->error('--source doit valoir '.implode(' ou ', LegalDocument::LIBELLE_SOURCES).'.');

            return self::FAILURE;
        }

        $entrees = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($entrees) || $entrees === []) {
            $this->error('La liste est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $confiance = (string) $this->option('confiance');
        $lot = [];

        foreach ($entrees as $entree) {
            if (! is_array($entree)) {
                continue;
            }

            // Garde-fou du chantier : ce canal n'écrit pas de titre. Un fichier
            // qui en porterait un a été confondu avec celui de
            // `mibeko:proposer-titres` — on s'arrête plutôt que d'en publier un.
            if (array_key_exists('titre', $entree)) {
                $this->error('Le fichier contient un champ « titre » : ce lot est destiné à '
                    .'mibeko:corriger-titres-publies, pas ici. Cette commande ne modifie jamais titre_officiel.');

                return self::FAILURE;
            }

            $id = (string) ($entree['id'] ?? '');
            $libelle = trim((string) ($entree['libelle'] ?? ''));

            if ($id === '' || $libelle === '') {
                continue;
            }

            if ($confiance !== '' && ($entree['confiance'] ?? null) !== $confiance) {
                continue;
            }

            $lot[] = [
                'id' => $id,
                'libelle' => $libelle,
                'titre_officiel' => $entree['titre_officiel'] ?? null,
                'confiance' => $entree['confiance'] ?? null,
            ];
        }

        if ($lot === []) {
            $this->error('Aucune entrée exploitable (id + libelle requis) dans le fichier.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $execute = (bool) $this->option('execute');
        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($execute && $jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        if (! $execute) {
            return $this->simuler($lot, $baseUrl, $source);
        }

        return $this->executer($lot, $baseUrl, $source, $jeton);
    }

    /**
     * @param  list<array<string, mixed>>  $lot
     */
    private function simuler(array $lot, string $baseUrl, string $source): int
    {
        $total = count($lot);

        $this->info("{$total} document(s) recevraient un libellé descriptif sur {$baseUrl}.");
        $this->line("Un seul appel par document : PATCH libelle_descriptif (+ source « {$source} »).");
        $this->line('Le titre officiel, le slug et le statut de curation ne changent pas.');
        $this->newLine();

        foreach ($lot as $entree) {
            $this->line(sprintf('  · %s', $entree['id']));
            $this->line(sprintf('     titre   : %s', Str::limit((string) ($entree['titre_officiel'] ?? '(inconnu)'), 70)));
            $this->line(sprintf('     libellé : %s', Str::limit($entree['libelle'], 70)));
        }

        $this->newLine();
        $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour écrire.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $lot
     */
    private function executer(array $lot, string $baseUrl, string $source, string $jeton): int
    {
        $total = count($lot);
        $rythme = max(0, (int) $this->option('rythme'));
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;

        $ecrits = 0;
        $echecs = [];
        $arendre = [];
        $rang = 0;

        foreach ($lot as $entree) {
            $rang++;
            $debut = microtime(true);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            $reponse = $this->patcher($jeton, "{$baseUrl}/legal-documents/{$entree['id']}", [
                'libelle_descriptif' => $entree['libelle'],
                'libelle_descriptif_source' => $source,
            ]);

            if ($reponse === null || $reponse->failed()) {
                $echecs[] = [Str::limit($entree['libelle'], 60), $this->motif($reponse)];
                $arendre[] = $entree;
                $this->line("<fg=red>✗</> {$avancement} {$entree['id']} — écriture refusée");
                $this->tenirLaCadence($intervalle, $debut, $rang < $total);

                continue;
            }

            $ecrits++;
            $this->line("<fg=green>✓</> {$avancement} ".Str::limit($entree['libelle'], 70));
            $this->tenirLaCadence($intervalle, $debut, $rang < $total);
        }

        $this->newLine();
        $this->info("{$ecrits} libellé(s) écrit(s) sur {$total}.");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Libellé visé', 'Motif'], $echecs);
            $this->warn(count($echecs).' document(s) non traité(s).');
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
        $this->line("Reprise : <fg=cyan>php artisan mibeko:appliquer-libelles --liste={$chemin} --execute</>");
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
