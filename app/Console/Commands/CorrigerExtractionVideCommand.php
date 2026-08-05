<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Corrige les documents marqués `extraction_status=completed` alors qu'ils
 * n'ont produit NI `structure_nodes` NI `articles` — bug de statut corrigé
 * dans `mibeko-python` (`journals.py`/`structurer.py`, 05/08/2026) : deux
 * titres d'acte détectés côte à côte (bruit OCR, sommaire mal filtré)
 * laissaient un acte sans aucun contenu, marqué "completed" quand même
 * (constaté sur 32 actes JO du 02/08/2026). Ce correctif ne répare que
 * l'ÉTIQUETTE de statut pour les documents déjà en base au moment du bug :
 * "failed" les fait apparaître dans le filtre « Échecs » déjà existant de
 * `/editor/ingestion` (`Ingestion.tsx`) au lieu de les faire passer pour
 * traités — il ne récupère PAS de contenu réel (aucune source à réextraire
 * ici que le `.md` du JO ENTIER, déjà attaché ; une vraie récupération de
 * contenu exige de revoir le découpage source par source, hors périmètre).
 *
 * `extraction_status` n'a aucun canal `PATCH /legal-documents` (absent de
 * la validation de `LegalDocumentController::update`), donc écriture directe
 * en base — jamais de SQL ad hoc : requête Eloquent, transaction, fichier de
 * retour arrière, exactement le nombre de lignes annoncé en simulation.
 *
 *   php artisan mibeko:corriger-extraction-vide                                   # simulation, dev
 *   php artisan mibeko:corriger-extraction-vide --connection=pgsql_prod_ro         # simulation, prod (lecture seule)
 *   php artisan mibeko:corriger-extraction-vide --connection=pgsql_prod_rw --execute --revert-file=…
 */
class CorrigerExtractionVideCommand extends Command
{
    protected $signature = 'mibeko:corriger-extraction-vide
        {--connection=pgsql : Connexion visée (pgsql_prod_ro en diagnostic, pgsql_prod_rw pour écrire)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}
        {--rapport= : Fichier où écrire la liste des documents concernés (JSON)}
        {--revert-file= : Où écrire l\'état avant correction (défaut : storage/app/)}';

    protected $description = 'Corrige extraction_status=completed sans structure_nodes ni article en "failed".';

    public function handle(): int
    {
        $connexion = (string) $this->option('connection');
        $execute = (bool) $this->option('execute');

        if ($execute && $connexion === 'pgsql_prod_ro') {
            $this->error('--execute exige une connexion en écriture (--connection=pgsql_prod_rw).');

            return self::FAILURE;
        }

        $db = DB::connection($connexion);

        $candidats = $db->table('legal_documents as d')
            ->where('d.extraction_status', 'completed')
            ->whereNull('d.deleted_at')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('structure_nodes as n')->whereColumn('n.document_id', 'd.id');
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('articles as a')->whereColumn('a.document_id', 'd.id')->whereNull('a.deleted_at');
            })
            ->select('d.id', 'd.titre_officiel', 'd.type_code', 'd.document_role', 'd.created_at')
            ->orderBy('d.created_at')
            ->get();

        if ($candidats->isEmpty()) {
            $this->info('Aucun document extraction_status=completed sans structure ni article.');

            return self::SUCCESS;
        }

        $this->table(
            ['Document', 'Type', 'Rôle', 'Créé le', 'ID'],
            $candidats->map(fn ($d) => [Str::limit((string) $d->titre_officiel, 55), $d->type_code, $d->document_role, $d->created_at, $d->id])
        );

        $rapport = (string) $this->option('rapport');
        if ($rapport !== '') {
            file_put_contents($rapport, json_encode($candidats->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Rapport : {$rapport}");
        }

        if (! $execute) {
            $this->newLine();
            $this->info("{$candidats->count()} document(s) seraient corrigés sur « {$connexion} » (extraction_status → failed).");
            $this->warn('SIMULATION — aucune écriture. Ajouter --execute pour corriger.');

            return self::SUCCESS;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-extraction-vide-'.now()->format('Ymd-His').'.json'));
        file_put_contents($fichierRetour, json_encode(
            $candidats->map(fn ($d) => ['id' => $d->id, 'extraction_status' => 'completed'])->values(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $ids = $candidats->pluck('id');
        $corriges = $db->transaction(
            fn () => $db->table('legal_documents')->whereIn('id', $ids)->update([
                'extraction_status' => 'failed',
                'updated_at' => now(),
            ])
        );

        $this->info("{$corriges} document(s) corrigé(s) sur « {$connexion} ».");

        if ($corriges !== $candidats->count()) {
            $this->error("Écart : {$candidats->count()} annoncé(s), {$corriges} effectivement touché(s) — à investiguer avant de poursuivre.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
