<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Supprime des relations nommément listées, via `DELETE /relations/{id}` —
 * jamais en SQL direct.
 *
 * Origine du besoin : `DocumentRelation::$fillable` omettait
 * `effective_date`/`confidence`/`meta` (corrigé), si bien que les relations
 * créées avant ce correctif ont perdu silencieusement leur date d'effet.
 * Aucune route `update` n'existe sur `document_relations` — recréer après
 * suppression est le chemin le plus sûr, via `mibeko:creer-relations-documents`.
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:supprimer-relations --liste=relations.json          # simulation
 *   php artisan mibeko:supprimer-relations --liste=relations.json --execute
 */
class SupprimerRelationsCommand extends Command
{
    protected $signature = 'mibeko:supprimer-relations
        {--liste= : Fichier JSON [{id, document}, …]}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--execute : Supprime réellement. Sans cette option, simulation seule.}';

    protected $description = 'Supprime des relations nommément listées via DELETE /relations/{id}.';

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
            $lignes[] = [Str::limit((string) ($e['document'] ?? ''), 60), (string) ($e['id'] ?? '')];
        }
        $this->table(['Relation', 'ID'], $lignes);

        if (! $execute) {
            $this->newLine();
            $this->info(count($entrees)." relation(s) seraient supprimées sur {$baseUrl}.");
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour supprimer.');

            return self::SUCCESS;
        }

        $supprimees = 0;
        $echecs = [];

        foreach ($entrees as $e) {
            $id = (string) ($e['id'] ?? '');
            $label = (string) ($e['document'] ?? $id);

            if ($id === '') {
                $echecs[] = [Str::limit($label, 50), 'id manquant'];

                continue;
            }

            $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)->delete("{$baseUrl}/relations/{$id}");

            if ($reponse->failed()) {
                $echecs[] = [Str::limit($label, 50), $this->motif($reponse)];
                $this->line("<fg=red>✗</> {$label}");

                continue;
            }

            $supprimees++;
            $this->line("<fg=green>✓</> {$label}");
        }

        $this->newLine();
        $this->info("{$supprimees} relation(s) supprimée(s).");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Relation', 'Motif'], $echecs);
        }

        return self::SUCCESS;
    }

    private function motif(Response $reponse): string
    {
        $corps = $reponse->json();

        return Str::limit((string) (data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
