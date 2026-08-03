<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Corrige le `type_code` de documents nommément listés, via l'API.
 *
 * `type_code` est un champ standard de `PATCH /api/v1/legal-documents/{id}`
 * (LegalDocumentController::update, validé par `exists:document_types,code`) :
 * contrairement au slug, aucune commande dédiée à la base n'est nécessaire ici,
 * l'API suffit et reste le canal à privilégier.
 *
 * Origine du besoin : le renommage d'un document dont le titre ne correspondait
 * plus au Journal officiel qui le portait (CorrigerTitresJournauxCommand) ne
 * change que le titre et le slug — le `type_code` hérité (`JO`) reste en place
 * et doit être corrigé séparément, une fois la vraie nature du texte connue.
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:corriger-type-code --mapping=type-codes.json          # simulation
 *   php artisan mibeko:corriger-type-code --mapping=type-codes.json --execute
 */
class CorrigerTypeCodeCommand extends Command
{
    protected $signature = 'mibeko:corriger-type-code
        {--mapping= : Fichier JSON [{id, type_code, titre}, …]}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Corrige le type_code de documents nommément listés via PATCH /legal-documents/{id}.';

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
                Str::limit((string) ($e['titre'] ?? $e['id'] ?? ''), 55),
                (string) ($e['type_code'] ?? ''),
                (string) ($e['id'] ?? ''),
            ];
        }
        $this->table(['Document', 'Nouveau type_code', 'ID'], $lignes);

        if (! $execute) {
            $this->newLine();
            $this->info(count($entrees)." document(s) seraient corrigés sur {$baseUrl}.");
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour corriger.');

            return self::SUCCESS;
        }

        $corriges = 0;
        $echecs = [];

        foreach ($entrees as $e) {
            $id = (string) ($e['id'] ?? '');
            $typeCode = (string) ($e['type_code'] ?? '');
            $label = (string) ($e['titre'] ?? $id);

            if ($id === '' || $typeCode === '') {
                $echecs[] = [Str::limit($label, 50), 'id ou type_code manquant'];

                continue;
            }

            $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)
                ->patch("{$baseUrl}/legal-documents/{$id}", ['type_code' => $typeCode]);

            if ($reponse->failed()) {
                $echecs[] = [Str::limit($label, 50), $this->motif($reponse)];
                $this->line("<fg=red>✗</> {$label}");

                continue;
            }

            $corriges++;
            $this->line("<fg=green>✓</> {$label} → {$typeCode}");
        }

        $this->newLine();
        $this->info("{$corriges} document(s) corrigé(s).");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Document', 'Motif'], $echecs);
        }

        return self::SUCCESS;
    }

    private function motif(Response $reponse): string
    {
        $corps = $reponse->json();

        return Str::limit((string) (data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
