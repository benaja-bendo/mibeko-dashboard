<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remplace le slug de documents nommément listés par une valeur donnée.
 *
 * `slug` n'est pas un champ exposé par `PATCH /api/v1/legal-documents/{id}`
 * (LegalDocumentController::update) : il n'existe pas de canal API pour cette
 * correction, d'où une commande dédiée en accès direct à la connexion `--connection`.
 *
 * Origine du besoin : `LegalDocument::generateUniqueSlug()` tronque à 80
 * caractères (`Str::limit(Str::slug($source), 80, '')`), une convention que
 * `CorrigerTitresJournauxCommand` a reproduite pour rester cohérente avec le
 * reste du corpus. Sur des titres inhabituellement longs, cette troncature
 * coupe l'information qui distingue le document (le numéro d'article visé, la
 * date de la Constitution modifiée…) : ce n'est pas une erreur du convertisseur,
 * mais une convention à lever au cas par cas, jamais globalement — d'où une
 * commande separee plutôt qu'un changement de `generateUniqueSlug()`.
 *
 * Contrairement à `CorrigerTitresJournauxCommand`, cette commande n'écarte PAS
 * les documents déjà publiés : le cas d'usage visé est de corriger un slug
 * généré dans la même session, avant qu'il n'ait eu le temps d'être partagé ou
 * indexé. Vérifier `updated_at` avant `--execute` si ce n'est plus le cas.
 *
 *   php artisan mibeko:corriger-slugs --mapping=slugs.json                                   # simulation
 *   php artisan mibeko:corriger-slugs --mapping=slugs.json --connection=pgsql_prod_rw --execute
 */
class CorrigerSlugsCommand extends Command
{
    protected $signature = 'mibeko:corriger-slugs
        {--mapping= : Fichier JSON [{id, slug}, …] relu par un humain}
        {--connection=pgsql_prod_ro : Connexion cible (pgsql_prod_ro en simulation, pgsql_prod_rw pour écrire)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}
        {--revert-file= : Où écrire le fichier de retour arrière (défaut : storage/app/)}';

    protected $description = 'Remplace le slug de documents nommément listés (aucun champ API équivalent).';

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
            $nouveauSlug = trim((string) ($entree['slug'] ?? ''));

            if (! is_string($id) || $nouveauSlug === '') {
                $this->warn('Entrée ignorée : `id` ou `slug` manquant.');

                continue;
            }

            $document = $db->table('legal_documents')
                ->select('id', 'titre_officiel', 'slug')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (! $document) {
                $this->warn("Document introuvable ou supprimé : {$id}");

                continue;
            }

            $collision = $db->table('legal_documents')
                ->where('slug', $nouveauSlug)
                ->where('id', '!=', $id)
                ->exists();

            if ($collision) {
                $this->warn("Slug déjà pris par un autre document, ignoré : {$nouveauSlug}");

                continue;
            }

            $lignes[] = [$document->id, $document->slug, $nouveauSlug];
            $retourArriere[] = ['id' => $document->id, 'slug' => $document->slug];
        }

        if ($lignes === []) {
            $this->info('Aucun slug à corriger.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Slug actuel', 'Nouveau slug'], $lignes);

        if (! $ecrire) {
            $this->newLine();
            $this->info(count($lignes).' slug(s) seraient corrigés. SIMULATION — aucune écriture.');
            $this->line('Pour écrire : --connection=pgsql_prod_rw --execute');

            return self::SUCCESS;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-slugs-'.now()->format('Ymd-His').'.json'));

        file_put_contents($fichierRetour, json_encode($retourArriere, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $touchees = 0;

        $db->transaction(function () use ($db, $mapping, &$touchees) {
            foreach ($mapping as $entree) {
                $id = $entree['id'] ?? null;
                $slug = trim((string) ($entree['slug'] ?? ''));

                if (! is_string($id) || $slug === '') {
                    continue;
                }

                $touchees += $db->table('legal_documents')
                    ->where('id', $id)
                    ->whereNull('deleted_at')
                    ->update(['slug' => $slug, 'updated_at' => now()]);
            }
        });

        $this->info("{$touchees} slug(s) corrigé(s).");

        return self::SUCCESS;
    }
}
