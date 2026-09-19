<?php

namespace App\Console\Commands;

use App\Models\LegalDocument;
use App\Services\Cdn\CdnPurgeScheduler;
use App\Services\Curation\SlugFromCitationGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Régénère le slug canonique des textes dont la citation (type, numéro, date
 * de signature) le permet — décision du 19/09/2026, `docs/decisions.md`,
 * schéma d'URL du fonds ; ticket benaja-bendo/mibeko-dashboard#156.
 *
 * Préalable non négociable : `numero_acte` doit être appliqué en production
 * (benaja-bendo/mibeko-dashboard#162, Temps 3 — écriture humaine). Sans lui,
 * cette commande ne trouve aucun candidat, ce qui n'est pas une erreur : elle
 * mesure alors simplement que le préalable n'est pas encore fait.
 *
 * **Refuse de tourner en entier** si une citation est partagée par deux
 * documents vivants (doublon à fusionner, ou annexe non nommée) : c'est le
 * garde-fou qu'aucune régénération ne doit contourner — deux textes ne
 * peuvent jamais réclamer la même URL. La liste des groupes en collision
 * sort AVANT tout calcul de slug, qu'on soit en simulation ou en écriture.
 *
 * Écrit en SQL direct sur `--connection`, jamais via Eloquent : les hooks de
 * `LegalDocument` qui tiennent `document_slug_aliases` (dashboard#155)
 * utilisent leurs propres modèles, liés à la connexion PAR DÉFAUT de
 * l'application — un `LegalDocument::on('pgsql_prod_rw')` écrirait le
 * document en production mais son alias en développement, invariant brisé
 * sans qu'aucune erreur ne le signale. Même doctrine que
 * `mibeko:corriger-slugs`, dont cette commande reprend la gestion manuelle
 * des alias telle quelle.
 *
 *   php artisan mibeko:regenerer-slugs                                              # simulation, lecture seule
 *   php artisan mibeko:regenerer-slugs --connection=pgsql_prod_rw --execute
 */
class RegenererSlugsCommand extends Command
{
    protected $signature = 'mibeko:regenerer-slugs
        {--connection=pgsql_prod_ro : Connexion cible (pgsql_prod_ro en simulation, pgsql_prod_rw pour écrire)}
        {--statut=published : Restreindre à un curation_status (vide = tous)}
        {--out= : Fichier JSON des candidats (défaut : storage/app/slugs-regeneres-<date>.json)}
        {--revert-file= : Où écrire le fichier de retour arrière (défaut : storage/app/)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Régénère le slug des textes dont la citation le permet (aucune écriture si une citation est partagée).';

    public function handle(SlugFromCitationGenerator $generateur, CdnPurgeScheduler $cdnPurge): int
    {
        $connexion = (string) $this->option('connection');
        $ecrire = (bool) $this->option('execute');

        if ($ecrire && $connexion === 'pgsql_prod_ro') {
            $this->error('--execute exige une connexion en écriture (--connection=pgsql_prod_rw).');

            return self::FAILURE;
        }

        $db = DB::connection($connexion);

        $colonneExiste = $db->selectOne(
            'select 1 as presente from information_schema.columns
             where table_name = ? and column_name = ?',
            ['legal_documents', 'numero_acte'],
        ) !== null;

        if (! $colonneExiste) {
            $this->error('La colonne `numero_acte` n\'existe pas sur '.$connexion.' — préalable dashboard#162 non déployé ici.');

            return self::FAILURE;
        }

        $requete = $db->table('legal_documents')
            ->whereNull('deleted_at')
            ->whereNotNull('numero_acte')
            ->select('id', 'slug', 'titre_officiel', 'type_code', 'numero_acte', 'date_signature', 'curation_status');

        $statut = (string) $this->option('statut');
        if ($statut !== '') {
            $requete->where('curation_status', $statut);
        }

        $documents = $requete->orderBy('titre_officiel')->get();

        // Garde-fou AVANT tout calcul de slug : une citation partagée par
        // deux documents vivants signale un doublon ou une annexe non
        // nommée (dashboard#162, § détection). Leur laisser traverser cette
        // commande leur donnerait la même URL canonique — la seule chose
        // qu'un filet d'alias ne peut pas réparer après coup.
        $collisions = $this->detecterCollisions($documents);

        if ($collisions !== []) {
            $this->error(count($collisions).' citation(s) partagée(s) par plusieurs documents vivants — RIEN N\'A ÉTÉ ÉCRIT.');
            foreach ($collisions as $groupe) {
                $premier = $groupe[0];
                $this->line(sprintf('  · %s n° %s du %s', $premier->type_code, $premier->numero_acte, $premier->date_signature ?? 'date inconnue'));
                foreach ($groupe as $document) {
                    $this->line(sprintf('      %s  %s', $document->id, mb_strimwidth((string) $document->titre_officiel, 0, 70, '…')));
                }
            }
            $this->newLine();
            $this->line('À fusionner (doublon) ou à nommer (annexe partageant la citation de son acte) avant de relancer — cf. mibeko:detecter-citations.');

            return self::FAILURE;
        }

        [$candidats, $dejaConformes, $sansCandidat] = $this->calculerCandidats($documents, $generateur);

        // Un candidat qui coïnciderait avec le slug canonique OU l'alias d'un
        // AUTRE document serait refusé par le filet (dashboard#155) au moment
        // d'écrire — ici, en amont, pour que la simulation le dise aussi.
        [$candidats, $collisionsExternes] = $this->ecarterCollisionsExternes($db, $candidats);

        $chemin = (string) ($this->option('out')
            ?: storage_path('app/slugs-regeneres-'.now()->format('Ymd-His').'.json'));
        file_put_contents($chemin, json_encode($candidats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->afficherLeResume($candidats, $dejaConformes, $sansCandidat, $collisionsExternes, $chemin);

        if (! $ecrire) {
            $this->newLine();
            $this->info(count($candidats).' slug(s) seraient régénérés. SIMULATION — aucune écriture.');
            $this->line('Pour écrire : --connection=pgsql_prod_rw --execute');

            return self::SUCCESS;
        }

        if ($candidats === []) {
            $this->info('Aucun slug à régénérer.');

            return self::SUCCESS;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/slugs-retour-'.now()->format('Ymd-His').'.json'));
        file_put_contents($fichierRetour, json_encode(
            array_map(fn (array $c) => ['id' => $c['id'], 'slug' => $c['ancien_slug']], $candidats),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        $touchees = 0;
        $alias = 0;

        $db->transaction(function () use ($db, $candidats, &$touchees, &$alias) {
            foreach ($candidats as $c) {
                $modifiees = $db->table('legal_documents')
                    ->where('id', $c['id'])
                    ->whereNull('deleted_at')
                    ->update(['slug' => $c['nouveau_slug'], 'updated_at' => now()]);

                if ($modifiees === 0) {
                    continue;
                }

                $touchees += $modifiees;

                // Le document reprend un de ses anciens slugs : il redevient
                // canonique, l'alias correspondant n'a plus lieu d'être.
                // (Improbable ici — un candidat dérivé de la citation ne
                // repasse pas par un slug antérieur — mais la même garde que
                // `corriger-slugs` coûte peu et referme le même invariant.)
                $db->table('document_slug_aliases')
                    ->where('slug', $c['nouveau_slug'])
                    ->where('legal_document_id', $c['id'])
                    ->delete();

                if (trim((string) $c['ancien_slug']) === '') {
                    continue;
                }

                $db->table('document_slug_aliases')->insert([
                    'id' => (string) Str::uuid(),
                    'slug' => $c['ancien_slug'],
                    'legal_document_id' => $c['id'],
                    'created_at' => now(),
                ]);
                $alias++;
            }
        });

        $this->info("{$touchees} slug(s) régénéré(s), {$alias} ancien(s) slug(s) conservé(s) en alias.");

        if ($touchees > 0) {
            $cdnPurge->scheduleAsync();
        }

        return self::SUCCESS;
    }

    /**
     * Groupes de documents (id, slug, titre_officiel…) qui partagent la même
     * citation (type, numéro, date de signature — casse ignorée). Vide si
     * aucune collision.
     *
     * @return list<list<object>>
     */
    private function detecterCollisions(Collection $documents): array
    {
        $parCitation = [];

        foreach ($documents as $document) {
            $cle = implode('|', [
                (string) $document->type_code,
                mb_strtolower((string) $document->numero_acte),
                (string) $document->date_signature,
            ]);
            $parCitation[$cle][] = $document;
        }

        return array_values(array_filter($parCitation, fn (array $groupe) => count($groupe) > 1));
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    private function calculerCandidats(Collection $documents, SlugFromCitationGenerator $generateur): array
    {
        $candidats = [];
        $dejaConformes = 0;
        $sansCandidat = 0;

        foreach ($documents as $document) {
            // `date_signature` sort de la connexion `--connection` comme une
            // chaîne (requête sur le query builder, pas Eloquent) : on la fait
            // porter par une instance non persistée pour réutiliser le même
            // générateur que les tests, sans dupliquer sa logique de date.
            $porteur = new LegalDocument([
                'type_code' => $document->type_code,
                'numero_acte' => $document->numero_acte,
                'date_signature' => $document->date_signature,
            ]);

            $nouveau = $generateur->candidat($porteur);

            if ($nouveau === null) {
                $sansCandidat++;

                continue;
            }

            if ($nouveau === $document->slug) {
                $dejaConformes++;

                continue;
            }

            $candidats[] = [
                'id' => $document->id,
                'ancien_slug' => $document->slug,
                'nouveau_slug' => $nouveau,
                'titre_officiel' => $document->titre_officiel,
            ];
        }

        return [$candidats, $dejaConformes, $sansCandidat];
    }

    /**
     * Écarte un candidat qui coïnciderait avec le slug canonique ou l'alias
     * d'un AUTRE document — la collision de citation interne est déjà
     * exclue plus haut, ceci couvre le cas résiduel d'un slug qui aurait été
     * l'ancienne URL de quelqu'un d'autre.
     *
     * @param  list<array<string, mixed>>  $candidats
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function ecarterCollisionsExternes(mixed $db, array $candidats): array
    {
        $retenus = [];
        $ecartes = [];

        foreach ($candidats as $c) {
            $canonique = $db->table('legal_documents')
                ->where('slug', $c['nouveau_slug'])
                ->where('id', '!=', $c['id'])
                ->whereNull('deleted_at')
                ->exists();

            $aliasAutrui = $db->table('document_slug_aliases')
                ->where('slug', $c['nouveau_slug'])
                ->where('legal_document_id', '!=', $c['id'])
                ->exists();

            if ($canonique || $aliasAutrui) {
                $ecartes[] = $c;

                continue;
            }

            $retenus[] = $c;
        }

        return [$retenus, $ecartes];
    }

    /**
     * @param  list<array<string, mixed>>  $candidats
     * @param  list<array<string, mixed>>  $collisionsExternes
     */
    private function afficherLeResume(array $candidats, int $dejaConformes, int $sansCandidat, array $collisionsExternes, string $chemin): void
    {
        if ($candidats !== []) {
            $this->table(
                ['ID', 'Ancien slug', 'Nouveau slug'],
                collect($candidats)->take(20)->map(fn (array $c) => [$c['id'], mb_strimwidth((string) $c['ancien_slug'], 0, 40, '…'), $c['nouveau_slug']])->all(),
            );
            if (count($candidats) > 20) {
                $this->line('  … et '.(count($candidats) - 20).' autre(s).');
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%d candidat(s) écrit(s) dans %s. %d déjà conforme(s) (slug inchangé). %d document(s) hors périmètre (type sans mot de citation, ou champ manquant).',
            count($candidats), $chemin, $dejaConformes, $sansCandidat,
        ));

        if ($collisionsExternes !== []) {
            $this->newLine();
            $this->warn(count($collisionsExternes).' candidat(s) écarté(s) : le nouveau slug est déjà le canonique ou l\'alias d\'un AUTRE document.');
            foreach ($collisionsExternes as $c) {
                $this->line(sprintf('  · %s → %s', $c['id'], $c['nouveau_slug']));
            }
        }
    }
}
