<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Complète `legal_documents.metadata` (source_url, fetched_at, autorite) pour
 * des documents ingérés en mode `web_upload`, qui n'ont donc pas hérité de la
 * provenance capturée par le pipeline standard (`structurer.py::merge_metadata`).
 *
 * mibeko-front#32 lit ces trois clés pour le bloc « Provenance » du site public
 * (`LegalDocumentEvidence.astro`) : sans `source_url`, la page affiche « Source
 * officielle : Non confirmée » même quand une source existe réellement dans
 * `mibeko-python/data/manifests/`.
 *
 * Fusion, pas remplacement (même sémantique que `merge_metadata()` côté
 * Python) : les clés déjà présentes dans `metadata` (`ingestion_mode`,
 * `latest_extraction_run_id`, …) sont conservées, seules `source_url`,
 * `fetched_at` et `autorite` sont ajoutées ou mises à jour. Un document dont
 * `source_url` est déjà renseigné est ignoré — la commande est donc rejouable
 * sans écraser une provenance déjà correcte.
 *
 * Canal DB directe : `metadata` n'est pas un champ accepté par
 * `PATCH /legal-documents/{id}` (LegalDocumentController::update) aujourd'hui.
 * Champ sans canal API → `pgsql_prod_rw`, transaction, fichier de retour
 * arrière écrit avant modification (docs/infra/production.md § 6, Temps 2).
 * Sûr sur des documents publiés : cette commande ne touche ni `titre_officiel`
 * ni `slug`, aucune URL publique ne change.
 *
 *   php artisan mibeko:corriger-provenance-documents --mapping=provenance.json                              # simulation
 *   php artisan mibeko:corriger-provenance-documents --mapping=provenance.json --connection=pgsql_prod_rw --execute
 */
class CorrigerProvenanceCommand extends Command
{
    protected $signature = 'mibeko:corriger-provenance-documents
        {--mapping= : Fichier JSON [{id, source_url, fetched_at, autorite}, …] relu par un humain}
        {--connection=pgsql_prod_ro : Connexion cible (pgsql_prod_ro en simulation, pgsql_prod_rw pour écrire)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}
        {--revert-file= : Où écrire le fichier de retour arrière (défaut : storage/app/)}';

    protected $description = 'Complète metadata.{source_url,fetched_at,autorite} de documents ingérés en web_upload, sans écraser une provenance déjà renseignée.';

    public function handle(): int
    {
        $chemin = (string) $this->option('mapping');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --mapping obligatoire : chemin d\'un fichier JSON lisible.');

            return self::FAILURE;
        }

        $mapping = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($mapping) || $mapping === []) {
            $this->error('Le fichier de correspondance est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $connexion = (string) $this->option('connection');
        $ecrire = (bool) $this->option('execute');

        if ($ecrire && $connexion === 'pgsql_prod_ro') {
            $this->error('--execute exige une connexion en écriture (--connection=pgsql_prod_rw).');

            return self::FAILURE;
        }

        $db = DB::connection($connexion);

        $lignes = [];
        $retourArriere = [];
        $aTraiter = [];

        foreach ($mapping as $entree) {
            $id = $entree['id'] ?? null;

            if (! is_string($id) || $id === '') {
                $this->warn('Entrée ignorée : `id` manquant.');

                continue;
            }

            $document = $db->table('legal_documents')
                ->select('id', 'document_key', 'titre_officiel', 'curation_status', 'metadata')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (! $document) {
                $this->warn("Document introuvable ou supprimé : {$id}");

                continue;
            }

            $metadataActuelle = json_decode((string) ($document->metadata ?? '{}'), true) ?: [];

            if (! empty($metadataActuelle['source_url'])) {
                $this->warn("Provenance déjà renseignée, ignoré : {$document->document_key}");

                continue;
            }

            $extra = array_filter([
                'source_url' => $entree['source_url'] ?? null,
                'fetched_at' => $entree['fetched_at'] ?? null,
                'autorite' => $entree['autorite'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            if (empty($extra['source_url'])) {
                $this->warn("Entrée ignorée : `source_url` manquant pour {$document->document_key}");

                continue;
            }

            $metadataFusionnee = array_merge($metadataActuelle, $extra);

            $lignes[] = [
                Str::limit($document->document_key, 40),
                $document->curation_status,
                Str::limit((string) $extra['source_url'], 50),
                $extra['autorite'] ?? '—',
            ];

            $retourArriere[] = [
                'id' => $document->id,
                'document_key' => $document->document_key,
                'metadata' => $metadataActuelle,
            ];

            $aTraiter[] = [
                'id' => $document->id,
                'metadata' => $metadataFusionnee,
            ];
        }

        if ($aTraiter === []) {
            $this->info('Aucun document à corriger.');

            return self::SUCCESS;
        }

        $this->table(['document_key', 'curation_status', 'source_url', 'autorite'], $lignes);

        if (! $ecrire) {
            $this->newLine();
            $this->info(count($aTraiter).' document(s) recevraient une provenance. SIMULATION — aucune écriture.');
            $this->line('Pour écrire : --connection=pgsql_prod_rw --execute');

            return self::SUCCESS;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-provenance-'.now()->format('Ymd-His').'.json'));

        // Le retour arrière est écrit AVANT la modification : si l'écriture
        // échoue au milieu, le fichier couvre déjà tout le lot.
        file_put_contents($fichierRetour, json_encode($retourArriere, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $touchees = 0;

        $db->transaction(function () use ($db, $aTraiter, &$touchees) {
            foreach ($aTraiter as $entree) {
                $touchees += $db->table('legal_documents')
                    ->where('id', $entree['id'])
                    ->whereNull('deleted_at')
                    ->update([
                        'metadata' => json_encode($entree['metadata'], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->info("{$touchees} document(s) corrigé(s).");

        return self::SUCCESS;
    }
}
