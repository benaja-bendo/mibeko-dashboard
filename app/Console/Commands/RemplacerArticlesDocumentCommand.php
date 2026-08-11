<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remplace en bloc la structure d'un document (structure_nodes, articles,
 * article_versions, curation_flags) sur une cible, par la version d'une source —
 * cas d'usage : un document déjà présent en prod (même id) dont le contenu source
 * a été ré-extrait/re-structuré en dev, et dont on veut pousser l'amélioration
 * sans passer par un nouvel id de document.
 *
 * **Dérogation délibérée à la doctrine « additive, jamais destructive » de
 * push_corpus.py** : ce script REMPLACE (supprime puis réinsère), là où
 * push_corpus n'ajoute que des documents absents de la cible et ne touche
 * jamais un document existant — précisément parce que la prod porte des
 * références utilisateur (tags, dossiers) SANS contrainte FK vers les articles,
 * qu'un remplacement orphelinerait en silence. Ce script ne s'autorise donc
 * QUE si la vérification de sûreté ci-dessous ne trouve AUCUNE référence de ce
 * genre, AUCUN `curation_status=published`, AUCUNE version d'article déjà
 * validée sur la cible — sinon il refuse, sans option pour forcer.
 *
 * Les deux bases doivent partager le MÊME id de document (c'est le seul lien) :
 * aucun remappage d'id n'est nécessaire pour structure_nodes/articles/
 * article_versions/curation_flags, dont les lignes sont recopiées telles
 * quelles (mêmes UUID), à l'exception de :
 *   - article_versions.source_run_id / source_media_file_id → NULL (l'extraction
 *     source n'existe que côté source ; le PDF source lui-même, lui, ne change
 *     pas et son media_file cible reste intact — jamais d'écriture MinIO ici) ;
 *   - curation_flags.resolved_by → réassigné à l'utilisateur de MÊME email côté
 *     cible (les tables users ne partagent pas les mêmes id entre bases).
 * `embedding` et `search_tsv` ne sont jamais copiés (régénérés par cron/trigger
 * côté cible, comme le fait déjà push_corpus.py).
 *
 *   php artisan mibeko:remplacer-articles-document --document=<id> \
 *       --connection=pgsql_prod_ro --rapport=storage/app/rapport.json
 *   php artisan mibeko:remplacer-articles-document --document=<id> \
 *       --connection=pgsql_prod_rw --execute
 */
class RemplacerArticlesDocumentCommand extends Command
{
    protected $signature = 'mibeko:remplacer-articles-document
        {--document= : ID du document à remplacer (identique source et cible)}
        {--source-connection=pgsql : Connexion source (dev)}
        {--connection=pgsql_prod_ro : Connexion cible (pgsql_prod_ro en diagnostic, pgsql_prod_rw pour écrire)}
        {--execute : Applique réellement le remplacement. Sans cette option, diagnostic seul.}
        {--rapport= : Fichier où écrire le rapport de diagnostic}
        {--revert-file= : Où écrire l\'état actuel de la cible avant remplacement (défaut : storage/app/)}';

    protected $description = 'Remplace structure_nodes/articles/article_versions/curation_flags d\'un document sur une cible par la version d\'une source (lecture seule par défaut).';

    public function handle(): int
    {
        $docId = (string) $this->option('document');

        if ($docId === '') {
            $this->error('Option --document obligatoire.');

            return self::FAILURE;
        }

        if ((bool) $this->option('execute')) {
            return $this->executer($docId);
        }

        return $this->diagnostiquer($docId);
    }

    /**
     * @return array<int, string> Liste des motifs de refus (vide = sûr)
     */
    private function verifierSurete(mixed $dbCible, string $docId, string $label): array
    {
        $erreurs = [];

        $doc = $dbCible->table('legal_documents')->where('id', $docId)->whereNull('deleted_at')->first();

        if ($doc === null) {
            $erreurs[] = "document absent (ou supprimé) sur {$label}";

            return $erreurs;
        }

        if ($doc->curation_status === 'published') {
            $erreurs[] = "document DÉJÀ PUBLIÉ sur {$label} — remplacement refusé";
        }

        $articleIds = $dbCible->table('articles')->where('document_id', $docId)->pluck('id');

        $valides = $dbCible->table('article_versions')
            ->whereIn('article_id', $articleIds)->where('validation_status', 'validated')->count();

        if ($valides > 0) {
            $erreurs[] = "{$valides} version(s) d'article déjà VALIDÉE(S) sur {$label} — remplacement refusé";
        }

        $refs = 0;
        $refs += $dbCible->table('taggables')->where(
            fn ($q) => $q->where('taggable_type', 'like', '%LegalDocument%')->where('taggable_id', $docId)
        )->orWhere(
            fn ($q) => $q->where('taggable_type', 'like', '%Article%')->whereIn('taggable_id', $articleIds)
        )->count();
        $refs += $dbCible->table('dossier_articles')->whereIn('article_id', $articleIds)->count();
        $refs += $dbCible->table('dossier_references')->where('target_id', $docId)->orWhereIn('target_id', $articleIds)->count();
        $refs += $dbCible->table('document_relations')
            ->where('source_doc_id', $docId)->orWhere('target_doc_id', $docId)
            ->orWhereIn('source_article_id', $articleIds)->orWhereIn('target_article_id', $articleIds)->count();

        if ($refs > 0) {
            $erreurs[] = "{$refs} référence(s) utilisateur (tags/dossiers/relations) vers ce document sur {$label} — orphelinerait des données humaines, remplacement refusé";
        }

        return $erreurs;
    }

    private function diagnostiquer(string $docId): int
    {
        $dbSource = DB::connection((string) $this->option('source-connection'));
        $dbCible = DB::connection((string) $this->option('connection'));

        $source = $dbSource->table('legal_documents')->where('id', $docId)->whereNull('deleted_at')->first();

        if ($source === null) {
            $this->error('Document absent de la source.');

            return self::FAILURE;
        }

        $erreurs = $this->verifierSurete($dbCible, $docId, 'la cible');

        $nArticlesSource = $dbSource->table('articles')->where('document_id', $docId)->whereNull('deleted_at')->count();
        $nNodesSource = $dbSource->table('structure_nodes')->where('document_id', $docId)->whereNull('deleted_at')->count();
        $nFlagsSource = $dbSource->table('curation_flags')->where('document_id', $docId)->count();
        $nFlagsSourceOuverts = $dbSource->table('curation_flags')->where('document_id', $docId)->where('resolved', false)->count();

        $cible = $dbCible->table('legal_documents')->where('id', $docId)->whereNull('deleted_at')->first();
        $nArticlesCible = $cible ? $dbCible->table('articles')->where('document_id', $docId)->whereNull('deleted_at')->count() : 0;

        $this->newLine();
        $this->table(['Champ', 'Source', 'Cible'], [
            ['titre', $source->titre_officiel, $cible->titre_officiel ?? '—'],
            ['curation_status', $source->curation_status, $cible->curation_status ?? '—'],
            ['articles vivants', $nArticlesSource, $nArticlesCible],
            ['structure_nodes', $nNodesSource, '—'],
            ['curation_flags (total / ouverts)', "{$nFlagsSource} / {$nFlagsSourceOuverts}", '—'],
        ]);

        if ($erreurs === []) {
            $this->info('Sûr à exécuter : aucune référence utilisateur, aucune publication, aucune version validée sur la cible.');
        } else {
            $this->newLine();
            $this->error('REMPLACEMENT REFUSÉ pour les motifs suivants :');
            foreach ($erreurs as $e) {
                $this->line("  · {$e}");
            }
        }

        $rapport = (string) $this->option('rapport');

        if ($rapport !== '') {
            file_put_contents($rapport, json_encode([
                'document_id' => $docId,
                'sur' => $erreurs === [],
                'erreurs' => $erreurs,
                'source' => ['articles' => $nArticlesSource, 'structure_nodes' => $nNodesSource, 'curation_flags' => $nFlagsSource],
                'cible' => ['articles' => $nArticlesCible],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Rapport : {$rapport}");
        }

        return self::SUCCESS;
    }

    private function executer(string $docId): int
    {
        $connexion = (string) $this->option('connection');

        if ($connexion === 'pgsql_prod_ro') {
            $this->error('--execute exige une connexion en écriture (--connection=pgsql_prod_rw).');

            return self::FAILURE;
        }

        $dbSource = DB::connection((string) $this->option('source-connection'));
        $dbCible = DB::connection($connexion);

        $erreurs = $this->verifierSurete($dbCible, $docId, 'la cible');

        if ($erreurs !== []) {
            $this->error('REMPLACEMENT REFUSÉ :');
            foreach ($erreurs as $e) {
                $this->line("  · {$e}");
            }

            return self::FAILURE;
        }

        $utilisateurCible = null;
        $userSource = $dbSource->table('users')
            ->whereIn('id', $dbSource->table('curation_flags')->where('document_id', $docId)->where('resolved', true)->pluck('resolved_by')->filter()->unique())
            ->first();

        if ($userSource !== null) {
            $utilisateurCible = $dbCible->table('users')->where('email', $userSource->email)->value('id');
        }

        // --- Retour arrière : état COMPLET de la cible avant toute suppression ---
        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-remplacement-articles-'.now()->format('Ymd-His').'.json'));

        $etatCible = [
            'document_id' => $docId,
            'legal_documents' => $dbCible->table('legal_documents')->where('id', $docId)->first(),
            'structure_nodes' => $dbCible->table('structure_nodes')->where('document_id', $docId)->get(),
            'articles' => $dbCible->table('articles')->where('document_id', $docId)->get(),
            'article_versions' => $dbCible->table('article_versions')
                ->whereIn('article_id', $dbCible->table('articles')->where('document_id', $docId)->pluck('id'))->get(),
            'curation_flags' => $dbCible->table('curation_flags')->where('document_id', $docId)->get(),
        ];
        file_put_contents($fichierRetour, json_encode($etatCible, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $curationStatusSource = $dbSource->table('legal_documents')->where('id', $docId)->value('curation_status');

        $dbCible->transaction(function () use ($dbSource, $dbCible, $docId, $utilisateurCible, $curationStatusSource) {
            $articleIdsCible = $dbCible->table('articles')->where('document_id', $docId)->pluck('id');

            // Ordre de suppression dicté par les FK (curation_flags → article_versions → articles → structure_nodes).
            $dbCible->table('curation_flags')->where('document_id', $docId)->delete();
            $dbCible->table('article_versions')->whereIn('article_id', $articleIdsCible)->delete();
            $dbCible->table('articles')->where('document_id', $docId)->delete();
            $dbCible->table('structure_nodes')->where('document_id', $docId)->delete();

            // Ordre d'insertion inverse (structure_nodes → articles → article_versions → curation_flags).
            $nodes = $dbSource->table('structure_nodes')->where('document_id', $docId)->whereNull('deleted_at')->get();
            foreach ($nodes->chunk(500) as $chunk) {
                $dbCible->table('structure_nodes')->insert($chunk->map(fn ($n) => (array) $n)->all());
            }

            $articles = $dbSource->table('articles')->where('document_id', $docId)->whereNull('deleted_at')->get();
            foreach ($articles->chunk(500) as $chunk) {
                $dbCible->table('articles')->insert($chunk->map(fn ($a) => (array) $a)->all());
            }

            $articleIdsSource = $articles->pluck('id');
            $versions = $dbSource->table('article_versions')->whereIn('article_id', $articleIdsSource)->get();
            foreach ($versions->chunk(500) as $chunk) {
                $dbCible->table('article_versions')->insert($chunk->map(function ($v) {
                    $row = (array) $v;
                    $row['source_run_id'] = null;
                    $row['source_media_file_id'] = null;
                    unset($row['embedding'], $row['search_tsv']);

                    return $row;
                })->all());
            }

            $flags = $dbSource->table('curation_flags')->where('document_id', $docId)->get();
            foreach ($flags->chunk(500) as $chunk) {
                $dbCible->table('curation_flags')->insert($chunk->map(function ($f) use ($utilisateurCible) {
                    $row = (array) $f;
                    if ($row['resolved_by'] !== null) {
                        $row['resolved_by'] = $utilisateurCible;
                    }

                    return $row;
                })->all());
            }

            $dbCible->table('legal_documents')->where('id', $docId)->update([
                'curation_status' => $curationStatusSource,
                'updated_at' => now(),
            ]);
        });

        $this->info('Remplacement terminé.');

        return self::SUCCESS;
    }
}
