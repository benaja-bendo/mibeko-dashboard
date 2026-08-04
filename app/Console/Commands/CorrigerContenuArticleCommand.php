<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Remplace le texte d'articles nommément listés, via l'API.
 *
 * `PATCH /api/v1/articles/{id}` avec `{content: "…"}` (ArticleController::update)
 * est le canal légitime : il versionne proprement (ferme la version active du
 * jour et en ouvre une nouvelle, ou met à jour en place si la version du jour
 * vient d'être créée) — jamais de SQL direct sur `article_versions`.
 *
 * Origine du besoin : certains actes issus d'une édition spéciale de Journal
 * officiel ont leur ours (masthead) et leur grille tarifaire FONDUS dans le même
 * article que leur formule de promulgation ou leurs visas — contrairement aux
 * Constitutions traitées par `RetirerArticlesMastheadCommand`, où masthead et
 * contenu réel étaient deux articles distincts, ici tout est mélangé dans un
 * seul bloc. Le retirer purement et simplement effacerait du texte juridique
 * réel. La correction relit donc le texte de remplacement dans le mapping —
 * jamais générée automatiquement par un motif — et le fait valider par un
 * humain avant `--execute`.
 *
 * Cadence/reprise alignées sur `mibeko:corriger-titres-publies` et
 * `mibeko:publier-vague` (régression du 04/08/2026 : lancée sans cadence sur
 * ~700 articles, cette commande a épuisé le quota `throttle:api` — 60
 * requêtes/minute sur le préfixe `/api/v1` — après 279 correctifs, puis tout
 * le reste a échoué en 429 « Too Many Attempts » sans une seule reprise).
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:corriger-contenu-article --mapping=contenus.json          # simulation
 *   php artisan mibeko:corriger-contenu-article --mapping=contenus.json --execute
 */
class CorrigerContenuArticleCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:corriger-contenu-article
        {--mapping= : Fichier JSON [{id, document, motif, content}, …]}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--rythme=40 : Articles par minute (quota API 60 req/min, 1 appel par article ici)}
        {--echecs= : Fichier où écrire les articles non corrigés, au format --mapping, pour relancer}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Remplace le texte d\'articles nommément listés via PATCH /articles/{id} (versionné).';

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
                Str::limit((string) ($e['document'] ?? ''), 30),
                Str::limit((string) ($e['motif'] ?? ''), 30),
                Str::limit((string) ($e['content'] ?? ''), 70),
            ];
        }
        $this->table(['Document', 'Motif', 'Nouveau contenu (début)'], $lignes);

        if (! $execute) {
            $this->newLine();
            $this->info(count($entrees)." article(s) seraient corrigés sur {$baseUrl}.");
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
            $content = (string) ($e['content'] ?? '');
            $label = (string) ($e['document'] ?? $id);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            if ($id === '' || $content === '') {
                $echecs[] = [Str::limit($label, 50), 'id ou content manquant'];
                $this->line("<fg=red>✗</> {$avancement} {$label}");

                continue;
            }

            $reponse = $this->patcher($jeton, "{$baseUrl}/articles/{$id}", ['content' => $content]);

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
        $this->info("{$corriges} article(s) corrigé(s) sur {$total}.");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Article', 'Motif'], $echecs);
            $this->warn(count($echecs).' article(s) non corrigé(s).');
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

    /** Le serveur sait mieux que nous quand revenir : `Retry-After` prime sur le backoff. */
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

    /**
     * Tient la cadence en déduisant le temps déjà passé sur l'article : sans
     * cette compensation, le lot avancerait bien plus lentement qu'annoncé.
     */
    private function tenirLaCadence(float $intervalle, float $debut, bool $encoreDesArticles): void
    {
        if ($intervalle <= 0 || ! $encoreDesArticles) {
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
        $this->line("Reprise : <fg=cyan>php artisan mibeko:corriger-contenu-article --mapping={$chemin} --execute</>");
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
