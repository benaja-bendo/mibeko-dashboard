<?php

namespace App\Console\Commands;

use App\Models\LegalDocument;
use Illuminate\Console\Command;

/**
 * Génère le slug des documents juridiques qui n'en ont pas.
 *
 * Le slug est la clé d'URL publique du site vitrine (`/codes/{slug}`). Il n'est
 * posé automatiquement que sur les écritures Eloquent (cf. `LegalDocument::booted`).
 * Or le pipeline d'ingestion Python écrit directement dans PostgreSQL, sans
 * passer par Eloquent : ces documents arrivent donc sans slug et, une fois
 * publiés, restent invisibles du site vitrine (qui filtre sur la présence d'un
 * slug pour éviter les URLs `/textes/undefined`).
 *
 * Cette commande répare ces lignes. Elle est **idempotente** (ne touche que les
 * slugs vides), sûre à lancer en production et planifiable : c'est le filet de
 * sécurité pour tout chemin d'écriture hors-Eloquent (ingestion Python, mise à
 * jour de masse SQL, insertion brute).
 */
class BackfillDocumentSlugs extends Command
{
    protected $signature = 'mibeko:backfill-document-slugs {--dry-run : Affiche le nombre de documents à traiter sans rien écrire}';

    protected $description = 'Génère le slug manquant des documents juridiques (ingestion Python directe en base, etc.).';

    public function handle(): int
    {
        $query = LegalDocument::withTrashed()
            ->where(function ($q) {
                $q->whereNull('slug')->orWhere('slug', '');
            });

        $missing = (clone $query)->count();

        if ($missing === 0) {
            $this->info('Aucun document sans slug : rien à faire.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$missing} document(s) sans slug seraient traités (dry-run, aucune écriture).");

            return self::SUCCESS;
        }

        $backfilled = 0;

        // chunkById ordonne par clé primaire : ne PAS ajouter d'orderBy, le
        // curseur sauterait des lignes au-delà du premier lot. saveQuietly écrit
        // sans déclencher d'événement (l'audit n'a pas à journaliser ce backfill
        // technique) ; chaque slug posé est visible des itérations suivantes, ce
        // qui garantit l'unicité au fil de l'eau comme dans la migration initiale.
        $query->chunkById(200, function ($documents) use (&$backfilled): void {
            foreach ($documents as $document) {
                $document->slug = LegalDocument::generateUniqueSlug(
                    $document->titre_officiel ?: $document->id,
                    $document->id,
                );
                $document->saveQuietly();
                $backfilled++;
            }
        });

        $this->info("Slugs générés : {$backfilled}");

        return self::SUCCESS;
    }
}
