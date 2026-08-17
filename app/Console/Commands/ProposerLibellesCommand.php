<?php

namespace App\Console\Commands;

use App\Services\Curation\LibelleDescriptifExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Propose un libellé descriptif pour les « actes en abrégé » — ces documents
 * dont l'intitulé se réduit à « Décret n° 2025-240 du 20 juin 2025. ».
 *
 * Ces intitulés sont FIDÈLES : le Journal officiel publie ces décisions en
 * abrégé, son sommaire n'annonce que « Nomination. » et l'en-tête n'imprime
 * aucun objet (vérifié le 16/08/2026 contre les markdowns MinerU, tirage de 10,
 * 9 fidèles). Le détecteur `mibeko:detecter-defauts-titres` classe d'ailleurs
 * la famille `I1_acte_en_abrege` en OBSERVATION, pas en défaut. Cette commande
 * ne répare donc RIEN : elle propose, à côté du titre officiel qu'elle ne
 * touche jamais, une paraphrase de ce que l'acte fait, pour que les listes, la
 * recherche et le fil d'Ariane cessent d'aligner des numéros muets.
 *
 * Ne fait AUCUNE écriture, jamais — même contrat que `mibeko:proposer-titres`,
 * pour la même raison : une phrase tirée d'un texte OCR n'est pas fiable à
 * 100 %, et le libellé est un champ public. Le fichier produit (--out) est une
 * table {id, libelle, …} relue par un humain, puis appliquée par
 * `mibeko:appliquer-libelles` (PATCH API, `libelle_descriptif` seul).
 *
 * Lecture seule : se connecte par défaut à `pgsql_prod_ro`.
 *
 *   php artisan mibeko:proposer-libelles --statut=published --out=/tmp/libelles.json
 */
class ProposerLibellesCommand extends Command
{
    protected $signature = 'mibeko:proposer-libelles
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--statut=published : Restreindre à un curation_status (vide = tous)}
        {--sans-libelle : Ignorer les documents qui ont déjà un libellé descriptif}
        {--out= : Fichier JSON de sortie (défaut : storage/app/libelles-proposes-<date>.json)}
        {--limit=0 : Limite le nombre de documents traités (0 = tous)}';

    protected $description = 'Propose, sans rien écrire, un libellé descriptif pour les actes en abrégé.';

    public function handle(LibelleDescriptifExtractor $extracteur): int
    {
        $connexion = (string) $this->option('connection');
        $db = DB::connection($connexion);

        // La colonne peut ne pas exister sur la cible : mesurer la population
        // AVANT de déployer la migration est l'ordre naturel des choses, et
        // c'est même le seul moyen de savoir si le chantier vaut le déploiement.
        // Sans ce test, la commande échouerait sur une prod pas encore migrée.
        $colonneExiste = $db->selectOne(
            'select 1 as presente from information_schema.columns
             where table_name = ? and column_name = ?',
            ['legal_documents', 'libelle_descriptif'],
        ) !== null;

        if (! $colonneExiste) {
            $this->warn('La colonne `libelle_descriptif` n\'existe pas sur la connexion '.$connexion
                .' — migration non déployée. Les propositions sont produites quand même ; '
                .'`libelle_actuel` sera nul et --sans-libelle est sans effet.');
            $this->newLine();
        }

        $requete = $db->table('legal_documents as ld')
            ->join('articles as a', function ($join) {
                $join->on('a.document_id', '=', 'ld.id')->whereNull('a.deleted_at');
            })
            ->join('article_versions as av', function ($join) {
                $join->on('av.article_id', '=', 'a.id')->whereRaw('upper_inf(av.validity_period)');
            })
            ->whereNull('ld.deleted_at')
            // Pré-filtre large, volontairement identique à la condition du
            // détecteur `I1_acte_en_abrege` : il ramène aussi des intitulés qui
            // finissent par hasard sur une date. C'est `estActeEnAbrege()` qui
            // tranche ensuite, en exigeant que le motif consomme TOUT l'intitulé
            // — le tri fin appartient au code testé, pas au SQL.
            ->whereRaw("ld.titre_officiel ~* 'n° *[0-9]'")
            ->whereRaw("btrim(ld.titre_officiel) ~* '(du|le) [0-9]{1,2}(er)? [a-zéûà]+ (1[6-9][0-9]{2}|20[0-9]{2})[.,]?$'")
            // Le premier article réel : sur un acte unitaire, c'est le
            // dispositif lui-même.
            ->whereRaw('a.ordre_affichage = (
                select min(a2.ordre_affichage) from articles a2
                where a2.document_id = ld.id and a2.deleted_at is null
            )')
            ->select(array_filter([
                'ld.id',
                'ld.slug',
                'ld.titre_officiel',
                $colonneExiste ? 'ld.libelle_descriptif' : null,
                'ld.curation_status',
                'av.contenu_texte',
            ]))
            ->orderBy('ld.titre_officiel');

        $statut = (string) $this->option('statut');
        if ($statut !== '') {
            $requete->where('ld.curation_status', $statut);
        }

        if ($this->option('sans-libelle') && $colonneExiste) {
            $requete->whereNull('ld.libelle_descriptif');
        }

        $documents = $requete->get();

        $propositions = [];
        $ecartes = [];
        $horsPerimetre = 0;
        $titresTronques = 0;

        foreach ($documents as $document) {
            if (! $extracteur->estActeEnAbrege($document->titre_officiel)) {
                $horsPerimetre++;

                continue;
            }

            $proposition = $extracteur->proposer($document->contenu_texte);

            if ($proposition['motif_rejet'] !== null) {
                $ecartes[$proposition['motif_rejet']] = ($ecartes[$proposition['motif_rejet']] ?? 0) + 1;

                continue;
            }

            if ($proposition['titre_probablement_tronque']) {
                $titresTronques++;
            }

            $propositions[] = [
                'id' => $document->id,
                'slug' => $document->slug,
                'titre_officiel' => $document->titre_officiel,
                'libelle_actuel' => $document->libelle_descriptif ?? null,
                'libelle' => $proposition['libelle'],
                'nature' => $proposition['nature'],
                'confiance' => $proposition['confiance'],
                'titre_probablement_tronque' => $proposition['titre_probablement_tronque'],
                'extrait_source' => $proposition['extrait_source'],
            ];
        }

        $chemin = (string) ($this->option('out')
            ?: storage_path('app/libelles-proposes-'.now()->format('Ymd-His').'.json'));

        $limite = (int) $this->option('limit');
        if ($limite > 0) {
            $propositions = array_slice($propositions, 0, $limite);
        }

        file_put_contents($chemin, json_encode($propositions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->afficherLeResume($propositions, $ecartes, $horsPerimetre, $titresTronques, $chemin);

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $propositions
     * @param  array<string, int>  $ecartes
     */
    private function afficherLeResume(
        array $propositions,
        array $ecartes,
        int $horsPerimetre,
        int $titresTronques,
        string $chemin,
    ): void {
        $this->table(
            ['Titre officiel', 'Libellé proposé', 'Nature', 'Confiance'],
            collect($propositions)->take(15)->map(fn ($p) => [
                mb_strimwidth((string) $p['titre_officiel'], 0, 34, '…'),
                mb_strimwidth((string) $p['libelle'], 0, 52, '…'),
                (string) ($p['nature'] ?? '—'),
                (string) $p['confiance'],
            ])->all(),
        );

        $parConfiance = collect($propositions)->countBy('confiance');

        $this->newLine();
        $this->info(sprintf(
            '%d proposition(s) écrite(s) dans %s — %d de confiance haute, %d à vérifier.',
            count($propositions),
            $chemin,
            $parConfiance->get('haute', 0),
            $parConfiance->get('a_verifier', 0),
        ));

        if ($horsPerimetre > 0) {
            $this->line(sprintf(
                '%d document(s) écarté(s) : leur intitulé finit par une date sans être un acte en abrégé '
                .'(corps avalé dans le titre, famille P1 du détecteur).',
                $horsPerimetre,
            ));
        }

        foreach ($ecartes as $motif => $nombre) {
            $this->line("{$nombre} document(s) écarté(s) : {$motif}.");
        }

        if ($titresTronques > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d document(s) dont le CORPS commence par « portant… », « fixant… » : leur objet a bien été '
                .'imprimé par le JO, c\'est le découpage qui l\'a détaché du titre. Le libellé proposé est bon, '
                .'mais leur titre_officiel est probablement tronqué — chantier distinct, hors de cette commande '
                .'(champ titre_probablement_tronque dans le fichier).',
                $titresTronques,
            ));
        }

        $this->newLine();
        $this->warn('AUCUNE ÉCRITURE — ce sont des propositions à relire. Le titre officiel n\'est jamais touché. '
            .'Une fois le fichier relu : '
            .'php artisan mibeko:appliquer-libelles --liste='.$chemin.' --execute');
    }
}
