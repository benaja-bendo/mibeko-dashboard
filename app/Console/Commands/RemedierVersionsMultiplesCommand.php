<?php

namespace App\Console\Commands;

use App\Models\ArticleVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remédiation dashboard#166 : retire les versions FERMÉES d'articles à
 * versions multiples qui ne sont pas de vrais amendements légaux — des
 * artefacts laissés par un mécanisme corrigé (`FusionnerFragmentsCommand`
 * avant son fix, entre autres) qui forkait une version sans jamais renseigner
 * `modifie_par_document_id`. Mesuré en prod le 20/09/2026 (`pgsql_prod_ro`) :
 * 2 486 articles concernés, 2 906 versions fermées, `modifie_par_document_id`
 * NUL sur les 37 593 lignes de la table sans exception. Contenu vérifié sur
 * un échantillon stratifié (longueur croissante/décroissante/non-monotone
 * entre versions) : la version ACTIVE est la bonne dans 100% des cas
 * examinés — corrections OCR, phrases tronquées recollées. La remédiation
 * n'a donc pas à choisir quel texte garder, seulement à retirer le reste.
 *
 * Critères de sélection d'un article (jamais d'un article isolément — la
 * clause `modifie_par_document_id is not null` porte sur TOUTES ses
 * versions, pas seulement celle qu'on retire) :
 *   - au moins 2 versions VIVANTES (`deleted_at is null`) ;
 *   - AUCUNE de ses versions ne porte de `modifie_par_document_id` — un
 *     article avec ne serait-ce qu'un vrai amendement enregistré n'est
 *     JAMAIS touché, même partiellement.
 * Versions retirées : uniquement les fermées (`not upper_inf(validity_period)`)
 * de ces articles. La version active n'est jamais soft-deletée.
 *
 * SoftDelete uniquement (`article_versions.deleted_at`, migration
 * 2026_09_19_230955) — un DELETE physique est un interdit absolu même
 * autorisé (`docs/infra/production.md` § 6). Idempotent : une version déjà
 * soft-deletée n'est plus comptée, donc un article remédié ne repasse plus
 * le premier filtre au run suivant.
 *
 *   php artisan mibeko:remedier-versions-multiples                                          # simulation
 *   php artisan mibeko:remedier-versions-multiples --connection=pgsql_prod_rw --limit=5 --execute
 *   php artisan mibeko:remedier-versions-multiples --connection=pgsql_prod_rw --execute
 */
class RemedierVersionsMultiplesCommand extends Command
{
    protected $signature = 'mibeko:remedier-versions-multiples
        {--connection=pgsql_prod_ro : Connexion cible (pgsql_prod_ro en simulation, pgsql_prod_rw pour écrire)}
        {--limit= : Ne traiter que les N premiers articles concernés (lot pilote)}
        {--revert-file= : Où écrire le fichier de retour arrière (défaut : storage/app/)}
        {--execute : Écrit réellement (soft-delete). Sans cette option, simulation seule.}';

    protected $description = "Retire (soft-delete) les versions fermées d'articles à versions multiples qui ne sont pas de vrais amendements (dashboard#166).";

    public function handle(): int
    {
        $connexion = (string) $this->option('connection');
        $ecrire = (bool) $this->option('execute');
        $limite = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($ecrire && $connexion === 'pgsql_prod_ro') {
            $this->error('--execute exige une connexion en écriture (--connection=pgsql_prod_rw).');

            return self::FAILURE;
        }

        $db = DB::connection($connexion);

        $articleIds = $db->table('article_versions')
            ->whereNull('deleted_at')
            ->select('article_id')
            ->groupBy('article_id')
            ->havingRaw('count(*) >= 2')
            ->havingRaw('count(*) filter (where modifie_par_document_id is not null) = 0')
            ->pluck('article_id');

        if ($limite !== null) {
            $articleIds = $articleIds->take($limite);
        }

        if ($articleIds->isEmpty()) {
            $this->info('Aucun article concerné.');

            return self::SUCCESS;
        }

        $versionsARetirer = $db->table('article_versions')
            ->whereIn('article_id', $articleIds)
            ->whereNull('deleted_at')
            ->whereRaw('not upper_inf(validity_period)')
            ->orderBy('article_id')
            ->orderBy('created_at')
            ->get(['id', 'article_id', 'created_at', 'validity_period', 'contenu_texte']);

        $parStatut = $db->table('articles as a')
            ->join('legal_documents as ld', 'ld.id', '=', 'a.document_id')
            ->whereIn('a.id', $articleIds)
            ->select('ld.curation_status')
            ->selectRaw('count(*) as n')
            ->groupBy('ld.curation_status')
            ->get();

        $this->table(['Articles concernés', 'Versions à retirer'], [[$articleIds->count(), $versionsARetirer->count()]]);
        $this->table(['curation_status du document', 'Articles'], $parStatut->map(fn ($r) => [$r->curation_status, $r->n])->all());

        if (! $ecrire) {
            $this->info("Simulation — rien n'a été écrit. Ajoutez --execute (avec --connection=pgsql_prod_rw) pour appliquer.");

            return self::SUCCESS;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-remediation-versions-'.now()->format('Ymd-His').'.json'));

        file_put_contents(
            $fichierRetour,
            json_encode($versionsARetirer->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $retirees = 0;
        DB::connection($connexion)->transaction(function () use ($connexion, $versionsARetirer, &$retirees) {
            foreach ($versionsARetirer->pluck('id')->chunk(500) as $chunk) {
                $retirees += ArticleVersion::on($connexion)->whereIn('id', $chunk->all())->delete();
            }
        });

        $this->info("{$retirees} version(s) retirée(s) (soft-delete).");

        return self::SUCCESS;
    }
}
