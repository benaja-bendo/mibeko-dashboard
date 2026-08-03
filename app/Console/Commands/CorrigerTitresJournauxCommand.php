<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Renomme les documents intitulés « Journal officiel n° … » qui sont en réalité
 * un texte unique (Constitution, loi constitutionnelle, arrêté).
 *
 * Le découpage d'un JO produit normalement un document par acte. Quand il
 * échoue, le JO entier reste un seul document et hérite du titre du journal :
 * personne ne cherche « Journal officiel n° 1-2002 », alors que le document
 * porte la Constitution du Congo et ses 190 articles.
 *
 * La qualification juridique d'un texte n'est PAS une décision d'outillage :
 * la commande ne devine aucun titre. Elle applique une table de correspondance
 * relue par un humain (`--mapping`), au format :
 *
 *   [ { "id": "<uuid>", "titre": "<nouveau titre officiel>" }, … ]
 *
 * Le slug est recalculé à partir du nouveau titre : ces documents sont tous en
 * brouillon, aucune URL publique ne pointe donc vers l'ancien slug.
 *
 * Écriture en production : voir docs/infra/production.md § 6. Les variables
 * PROD_RW_DB_* s'exportent à la main dans le shell, jamais dans un fichier.
 * Aucun modèle Eloquent ici — ils sont liés à la connexion par défaut et
 * viseraient le développement sans que cela se voie (cf. ProdPreflightCommand).
 */
class CorrigerTitresJournauxCommand extends Command
{
    protected $signature = 'mibeko:corriger-titres-jo
        {--mapping= : Fichier JSON [{id, titre}, …] relu par un humain}
        {--connection=pgsql_prod_ro : Connexion cible (pgsql_prod_ro en simulation, pgsql_prod_rw pour écrire)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}
        {--revert-file= : Où écrire le fichier de retour arrière (défaut : storage/app/)}';

    protected $description = 'Renomme les documents « Journal officiel n° … » qui portent en réalité un texte unique.';

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

        foreach ($mapping as $entree) {
            $id = $entree['id'] ?? null;
            $nouveauTitre = trim((string) ($entree['titre'] ?? ''));

            if (! is_string($id) || $nouveauTitre === '') {
                $this->warn('Entrée ignorée : `id` ou `titre` manquant.');

                continue;
            }

            $document = $db->table('legal_documents')
                ->select('id', 'titre_officiel', 'slug', 'curation_status')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (! $document) {
                $this->warn("Document introuvable ou supprimé : {$id}");

                continue;
            }

            // Garde-fou : un document déjà publié porte une URL connue du public
            // et de Google. Le renommer n'est pas une correction de brouillon.
            if ($document->curation_status === 'published') {
                $this->warn("Document publié, ignoré (renommage hors périmètre) : {$id}");

                continue;
            }

            $nouveauSlug = $this->slugUnique($db, $nouveauTitre, $id);

            $lignes[] = [
                Str::limit($document->titre_officiel, 42),
                Str::limit($nouveauTitre, 46),
                Str::limit($nouveauSlug, 34),
            ];

            $retourArriere[] = [
                'id' => $document->id,
                'titre' => $document->titre_officiel,
                'slug' => $document->slug,
            ];
        }

        if ($lignes === []) {
            $this->info('Aucun document à renommer.');

            return self::SUCCESS;
        }

        $this->table(['Titre actuel', 'Nouveau titre', 'Nouveau slug'], $lignes);

        if (! $ecrire) {
            $this->newLine();
            $this->info(count($lignes).' document(s) seraient renommés. SIMULATION — aucune écriture.');
            $this->line('Pour écrire : --connection=pgsql_prod_rw --execute');

            return self::SUCCESS;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-titres-jo-'.now()->format('Ymd-His').'.json'));

        // Le retour arrière est écrit AVANT la modification : si l'écriture
        // échoue au milieu, le fichier couvre déjà tout le lot.
        file_put_contents($fichierRetour, json_encode($retourArriere, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $touchees = 0;

        $db->transaction(function () use ($db, $mapping, &$touchees) {
            foreach ($mapping as $entree) {
                $id = $entree['id'] ?? null;
                $titre = trim((string) ($entree['titre'] ?? ''));

                if (! is_string($id) || $titre === '') {
                    continue;
                }

                $touchees += $db->table('legal_documents')
                    ->where('id', $id)
                    ->whereNull('deleted_at')
                    ->where('curation_status', '!=', 'published')
                    ->update([
                        'titre_officiel' => $titre,
                        'slug' => $this->slugUnique($db, $titre, $id),
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->info("{$touchees} document(s) renommé(s).");

        return self::SUCCESS;
    }

    /**
     * Slug dérivé du titre, suffixé si un autre document le porte déjà.
     */
    private function slugUnique(mixed $db, string $titre, string $idCourant): string
    {
        $base = Str::slug(Str::limit($titre, 80, ''));
        $slug = $base;
        $suffixe = 2;

        while ($db->table('legal_documents')->where('slug', $slug)->where('id', '!=', $idCourant)->exists()) {
            $slug = $base.'-'.$suffixe++;
        }

        return $slug;
    }
}
