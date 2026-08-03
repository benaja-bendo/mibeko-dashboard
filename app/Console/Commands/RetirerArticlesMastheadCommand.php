<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Retire les articles qui ne sont pas du contenu juridique : l'ours du Journal
 * officiel (masthead) et la grille tarifaire des abonnements, capturés par
 * MinerU comme s'ils étaient les deux premiers « articles » du document.
 *
 * Passe par `DELETE /api/v1/articles/{id}` — jamais de SQL direct. Cette route
 * fait un SoftDelete (Article utilise SoftDeletes) : réversible via `restore()`,
 * auditée, gardée par `role:editor|admin`.
 *
 * Portée volontairement étroite : cette commande ne sait retirer QUE des
 * articles listés nommément dans le fichier `--liste`, jamais par motif ou par
 * numero_article deviné à la volée — la qualification de ce qui est « masthead »
 * plutôt que contenu réel reste une lecture humaine du texte, pas une règle
 * générique.
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:retirer-articles-masthead --liste=articles-masthead.json          # simulation
 *   php artisan mibeko:retirer-articles-masthead --liste=articles-masthead.json --execute
 */
class RetirerArticlesMastheadCommand extends Command
{
    protected $signature = 'mibeko:retirer-articles-masthead
        {--liste= : Fichier JSON [{id, document, numero_article, motif}, …]}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--execute : Retire réellement. Sans cette option, simulation seule.}';

    protected $description = 'Retire des articles non-juridiques (masthead JO) via DELETE /api/v1/articles (soft-delete réversible).';

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

        $baseUrl = rtrim((string) $this->option('base-url'), '/');

        $lignes = [];
        foreach ($entrees as $e) {
            $lignes[] = [
                Str::limit((string) ($e['document'] ?? ''), 32),
                (string) ($e['numero_article'] ?? ''),
                Str::limit((string) ($e['motif'] ?? ''), 40),
                (string) ($e['id'] ?? ''),
            ];
        }
        $this->table(['Document', 'numero_article', 'Motif', 'ID article'], $lignes);

        if (! $execute) {
            $this->newLine();
            $this->info(count($entrees)." article(s) seraient retirés sur {$baseUrl} (soft-delete, réversible).");
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour retirer.');

            return self::SUCCESS;
        }

        $retires = 0;
        $echecs = [];

        foreach ($entrees as $e) {
            $id = (string) ($e['id'] ?? '');
            $label = (string) ($e['document'] ?? $id).' — '.(string) ($e['numero_article'] ?? '');

            if ($id === '') {
                $echecs[] = [Str::limit($label, 50), 'id manquant dans le fichier'];

                continue;
            }

            $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)
                ->delete("{$baseUrl}/articles/{$id}");

            if ($reponse->failed()) {
                $echecs[] = [Str::limit($label, 50), $this->motif($reponse)];
                $this->line("<fg=red>✗</> {$label}");

                continue;
            }

            $retires++;
            $this->line("<fg=green>✓</> {$label}");
        }

        $this->newLine();
        $this->info("{$retires} article(s) retiré(s).");

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
