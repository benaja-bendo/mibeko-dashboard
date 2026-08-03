<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:corriger-contenu-article --mapping=contenus.json          # simulation
 *   php artisan mibeko:corriger-contenu-article --mapping=contenus.json --execute
 */
class CorrigerContenuArticleCommand extends Command
{
    protected $signature = 'mibeko:corriger-contenu-article
        {--mapping= : Fichier JSON [{id, document, motif, content}, …]}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
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

        $corriges = 0;
        $echecs = [];

        foreach ($entrees as $e) {
            $id = (string) ($e['id'] ?? '');
            $content = (string) ($e['content'] ?? '');
            $label = (string) ($e['document'] ?? $id);

            if ($id === '' || $content === '') {
                $echecs[] = [Str::limit($label, 50), 'id ou content manquant'];

                continue;
            }

            $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)
                ->patch("{$baseUrl}/articles/{$id}", ['content' => $content]);

            if ($reponse->failed()) {
                $echecs[] = [Str::limit($label, 50), $this->motif($reponse)];
                $this->line("<fg=red>✗</> {$label}");

                continue;
            }

            $corriges++;
            $this->line("<fg=green>✓</> {$label}");
        }

        $this->newLine();
        $this->info("{$corriges} article(s) corrigé(s).");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Article', 'Motif'], $echecs);
        }

        return self::SUCCESS;
    }

    private function motif(Response $reponse): string
    {
        $corps = $reponse->json();

        return Str::limit((string) (data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
