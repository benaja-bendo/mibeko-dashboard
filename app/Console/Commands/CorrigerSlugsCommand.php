<?php

namespace App\Console\Commands;

use App\Services\Cdn\CdnPurgeScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * les documents déjà publiés. Depuis le filet d'alias (mibeko-dashboard#155),
 * c'est sans danger pour les liens entrants : l'ancien slug est conservé dans
 * `document_slug_aliases`, l'API continue de le résoudre et le site redirige
 * en 301 vers le nouveau. La cible doit donc avoir joué la migration qui crée
 * cette table — sinon l'insertion échoue et la transaction est annulée, aucun
 * slug n'est touché.
 *
 * Aucun modèle Eloquent ici — ils sont liés à la connexion par défaut et
 * viseraient le développement sans que cela se voie (cf. ProdPreflightCommand) ;
 * l'invariant croisé slug canonique / alias (voir la migration) est donc
 * revérifié à la main avant d'écrire.
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

    protected $description = 'Remplace le slug de documents nommément listés (aucun champ API équivalent) ; l\'ancien reste résolvable (alias).';

    public function handle(CdnPurgeScheduler $cdnPurge): int
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
        /** @var array<string, array{ancien: string|null, nouveau: string}> $corrections */
        $corrections = [];

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

            if ($document->slug === $nouveauSlug) {
                $this->warn("Déjà à jour, ignoré : {$nouveauSlug}");

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

            // Une URL qui a désigné un texte ne doit jamais en désigner un autre.
            $ancienneUrlAutrui = $db->table('document_slug_aliases')
                ->where('slug', $nouveauSlug)
                ->where('legal_document_id', '!=', $id)
                ->exists();

            if ($ancienneUrlAutrui) {
                $this->warn("Slug déjà l'ancienne URL d'un autre document, ignoré : {$nouveauSlug}");

                continue;
            }

            $lignes[] = [$document->id, $document->slug, $nouveauSlug];
            $retourArriere[] = ['id' => $document->id, 'slug' => $document->slug];
            $corrections[$document->id] = ['ancien' => $document->slug, 'nouveau' => $nouveauSlug];
        }

        if ($lignes === []) {
            $this->info('Aucun slug à corriger.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Slug actuel (devient alias)', 'Nouveau slug'], $lignes);

        if (! $ecrire) {
            $this->newLine();
            $this->info(count($lignes).' slug(s) seraient corrigés, chaque slug actuel restant résolvable comme alias. SIMULATION — aucune écriture.');
            $this->line('Pour écrire : --connection=pgsql_prod_rw --execute');

            return self::SUCCESS;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-slugs-'.now()->format('Ymd-His').'.json'));

        file_put_contents($fichierRetour, json_encode($retourArriere, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $touchees = 0;
        $alias = 0;

        $db->transaction(function () use ($db, $corrections, &$touchees, &$alias) {
            foreach ($corrections as $id => $correction) {
                $modifiees = $db->table('legal_documents')
                    ->where('id', $id)
                    ->whereNull('deleted_at')
                    ->update(['slug' => $correction['nouveau'], 'updated_at' => now()]);

                if ($modifiees === 0) {
                    continue;
                }

                $touchees += $modifiees;

                // Le document reprend un de ses anciens slugs : il redevient
                // canonique, l'alias n'a plus lieu d'être.
                $db->table('document_slug_aliases')
                    ->where('slug', $correction['nouveau'])
                    ->where('legal_document_id', $id)
                    ->delete();

                if (trim((string) $correction['ancien']) === '') {
                    continue;
                }

                $db->table('document_slug_aliases')->insert([
                    'id' => (string) Str::uuid(),
                    'slug' => $correction['ancien'],
                    'legal_document_id' => $id,
                    'created_at' => now(),
                ]);
                $alias++;
            }
        });

        $this->info("{$touchees} slug(s) corrigé(s), {$alias} ancien(s) slug(s) conservé(s) en alias.");

        // Purge CDN (dashboard#161) : un slug canonique qui change déplace
        // l'URL publique du texte — l'ancienne doit désormais répondre en 301
        // (mibeko-site#45) au lieu d'une copie en cache, la nouvelle doit être
        // servie sans attendre l'expiration.
        if ($touchees > 0) {
            $cdnPurge->scheduleAsync();
        }

        return self::SUCCESS;
    }
}
