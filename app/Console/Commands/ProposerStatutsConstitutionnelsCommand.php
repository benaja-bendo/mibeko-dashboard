<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Propose le classement des 26 textes constitutionnels du corpus publié —
 * mibeko-dashboard#31.
 *
 * Le 10/08/2026, 16 de ces textes avaient été tranchés à la main (Constitution
 * de 1973, Acte fondamental de 1997, Constitution de 2015, etc.). Le corpus a
 * grossi depuis (795 → 1078 documents publiés) et a fait entrer 14 textes
 * constitutionnels supplémentaires, tous encore `statut=vigueur` sans être
 * vérifiés — le filet de `statut_verifie_le IS NULL` les affiche « non
 * vérifié » côté public (jamais faussement « en vigueur »), mais le travail
 * éditorial reste incomplet.
 *
 * Chronologie établie contre deux sources indépendantes de la base de
 * développement Mibeko :
 *   - sgg.cg, page officielle « Constitutions antérieures de la République
 *     du Congo » (Secrétariat Général du Gouvernement) ;
 *   - la Digithèque de matériaux juridiques et politiques, Université de
 *     Perpignan (référence académique en droit constitutionnel comparé).
 * Les deux s'accordent avec les 16 textes déjà tranchés le 10/08 : aucune
 * contradiction trouvée.
 *
 * LECTURE SEULE : résout les titres en IDs réels et produit deux fichiers à
 * relire, jamais d'écriture directe.
 *   - `--out-statuts`   : {id, document, motif, statut}
 *                         → mibeko:corriger-statut-document
 *   - `--out-relations` : {source_doc_id, target_doc_id, relation_type,
 *                          effective_date, commentaire, document}
 *                         → mibeko:creer-relations-documents
 *
 * Trou de sourcing connu, non traité ici : la Constitution du 8 juillet 1979
 * elle-même n'est PAS dans le corpus — seules trois lois qui l'amendent
 * (1980, 1984 ×2, 1990) le sont. Ces lois sont donc proposées à `abroge`
 * (leur régime de base a disparu) mais sans relation, faute de cible.
 */
class ProposerStatutsConstitutionnelsCommand extends Command
{
    /**
     * Titre exact (tel qu'en base) → nouveau statut. Seuls les textes non
     * encore vérifiés le 03/09/2026 figurent ici — les 16 déjà tranchés le
     * 10/08 n'y sont pas repris pour ne jamais écraser une vérification déjà
     * établie.
     *
     * @var array<string, string>
     */
    private const STATUTS = [
        // Série de 1959 : régime provisoire de la jeune république autonome,
        // entièrement remplacé par l'adoption de la Constitution de 1961.
        "Loi constitutionnelle n° 3 du 16 février 1959, suspendant provisoirement l'application de l'article 2 de la loi constitutionnelle n° 1 du 28 novembre 1958" => 'abroge',
        'Loi constitutionnelle n° 4 du 20 février 1959 relative à l\'Assemblée législative' => 'abroge',
        'LOI CONSTITUTIONNELLE N° 5 DU 20 FEVRIER 1959 RELATIVE AU GOVERNEMENT DE LA REPUBLICQUE' => 'abroge',
        'Loi constitutionnelle n° 9 du 3 novembre 1959 relative à la devise de la République du Congo.' => 'abroge',
        'Loi constitutionnelle n° 11 du 21 novembre 1959 relative à la présidence de la République' => 'abroge',

        // Constitution de 1961 : remplacée après les « Trois Glorieuses »
        // d'août 1963 par la Constitution du 8 décembre 1963.
        'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo' => 'abroge',

        // Constitution de 1963 : remplacée après le coup d'État de 1968 (Acte
        // fondamental du 14 août 1968, absent du corpus) puis la Constitution
        // de la République Populaire du Congo du 30 décembre 1969.
        'Constitution de la République du Congo du 8 décembre 1963' => 'abroge',

        // Constitution de 1969 : remplacée par celle du 24 juin 1973 (déjà
        // vérifiée `abroge` le 10/08) — l'ordonnance de promulgation suit le
        // même sort que le texte qu'elle promulgue.
        'Constitution de la République Populaire du Congo du 30 décembre 1969' => 'abroge',
        'Ordonnance n° 40-69 du 31 décembre 1969, portant promulgation de la constitution de la République Populaire du Congo' => 'abroge',

        // Acte fondamental de 1991 : remplacé par la Constitution de 1992.
        'Acte fondamental de la République du Congo du 4 juin 1991' => 'abroge',

        // Constitution de 1992 : suspendue puis remplacée par l'Acte
        // fondamental du 24 octobre 1997 (guerre civile de 1997, déjà
        // vérifié `abroge` le 10/08).
        'Constitution de la République du Congo du 15 mars 1992' => 'abroge',

        // Constitution de 2002 : remplacée par la Constitution du 25 octobre
        // 2015 (déjà vérifiée `vigueur` le 10/08).
        'Constitution de la République du Congo du 20 janvier 2002' => 'abroge',

        // Amendements à la Constitution du 8 juillet 1979 — absente du
        // corpus (trou de sourcing distinct). Leur régime de base a de toute
        // façon disparu depuis : abrogés au même titre que les amendements
        // déjà vérifiés de 1980 (LOI No 25-80) et 1990 (LOI No 001-90).
        'ORDONNANCE No 019-84 du 23 août 1984, portant modification de certaines dispositions de la Constitution du 8 juillet 1979.' => 'abroge',
        "LOI N° 076-84 du 7 décembre 1984, portant ratification de l'Ordonnance no 019-84 du 23 ao ut 1984, portant modification de Certaines dispositions de la Constitution du 8 juillet 1979." => 'abroge',
    ];

    /**
     * Chaîne de succession complète (source abroge/crée target), y compris
     * les textes déjà vérifiés le 10/08 : sans cette section, le graphe
     * resterait à moitié peuplé et incohérent à lire.
     *
     * @var list<array{source: string, target: string, type: string, date: string, commentaire: string}>
     */
    private const RELATIONS = [
        // Les 12 lois constitutionnelles de 1959 remplacées d'un bloc.
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'LOI CONSTITUTIONNELLE NUMERO 1', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'LOI CONSTITUTIONNELLE NUMERO 2', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => "Loi constitutionnelle n° 3 du 16 février 1959, suspendant provisoirement l'application de l'article 2 de la loi constitutionnelle n° 1 du 28 novembre 1958", 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'Loi constitutionnelle n° 4 du 20 février 1959 relative à l\'Assemblée législative', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'LOI CONSTITUTIONNELLE N° 5 DU 20 FEVRIER 1959 RELATIVE AU GOVERNEMENT DE LA REPUBLICQUE', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'LOI CONSTITUTIONNELLE N° 6 DU 20 FEVRIER 1959 RELATIVE AUX RAPPORTS ENTRE LES POUVOIRS PUBLICS', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'LOI CONSTITUTIONNELLE N° 7 DU 20 FÉVRIER 1959 RELATIVE A LA MISE EN PLACE DES INSTITUTIONS', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'Loi constitutionnelle n° 8 du 18 août 1959, fixant le drapeau de la République du Congo', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'Loi constitutionnelle n° 9 du 3 novembre 1959 relative à la devise de la République du Congo.', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => "Loi constitutionnelle n° 10 du 21 novembre 1959 relative à l'émme national de la République du Congo", 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => 'Loi constitutionnelle n° 11 du 21 novembre 1959 relative à la présidence de la République', 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],
        ['source' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'target' => "Loi constitutionnelle n° 12 du 7 décembre 1959 relative au titre de l'Assemblée législative de la République du Congo.", 'type' => 'ABROGE', 'date' => '1961-03-02', 'commentaire' => 'Adoption de la Constitution de 1961 : remplace le régime provisoire de 1959.'],

        // La chaîne des régimes successifs.
        ['source' => 'Constitution de la République du Congo du 8 décembre 1963', 'target' => 'Loi n° 22-61 du 2 mars 1961 portant adoption de la Constitution de la République du Congo', 'type' => 'ABROGE', 'date' => '1963-12-08', 'commentaire' => "« Trois Glorieuses » d'août 1963 : chute de Fulbert Youlou, nouvelle Constitution adoptée par référendum le 8 décembre 1963."],
        ['source' => 'Constitution de la République Populaire du Congo du 30 décembre 1969', 'target' => 'Constitution de la République du Congo du 8 décembre 1963', 'type' => 'ABROGE', 'date' => '1969-12-31', 'commentaire' => "Coup d'État du 4 août 1968 (Acte fondamental du 14 août 1968, absent du corpus) puis Constitution de la République Populaire du Congo, promulguée le 31 décembre 1969."],
        ['source' => 'Ordonnance n° 40-69 du 31 décembre 1969, portant promulgation de la constitution de la République Populaire du Congo', 'target' => 'Constitution de la République Populaire du Congo du 30 décembre 1969', 'type' => 'CREE', 'date' => '1969-12-31', 'commentaire' => 'Instrument de promulgation de la Constitution du 30 décembre 1969.'],
        ['source' => 'Constitution de la République Populaire du Congo du 24 juin 1973', 'target' => 'Constitution de la République Populaire du Congo du 30 décembre 1969', 'type' => 'ABROGE', 'date' => '1973-06-24', 'commentaire' => 'Nouvelle Constitution adoptée par référendum le 24 juin 1973, sur proposition du 2e congrès extraordinaire du PCT.'],
        ['source' => 'Acte fondamental de la République du Congo du 4 juin 1991', 'target' => 'Constitution de la République Populaire du Congo du 24 juin 1973', 'type' => 'ABROGE', 'date' => '1991-06-04', 'commentaire' => "Conférence nationale souveraine de 1991 : la Constitution de 1979 (absente du corpus) succédait déjà à celle de 1973 ; l'Acte fondamental de 1991 clôt cette lignée en instaurant la transition démocratique."],
        ['source' => 'Constitution de la République du Congo du 15 mars 1992', 'target' => 'Acte fondamental de la République du Congo du 4 juin 1991', 'type' => 'ABROGE', 'date' => '1992-03-15', 'commentaire' => 'Constitution de la Troisième République, adoptée par référendum (96,26 % pour) le 15 mars 1992.'],
        ['source' => 'Acte fondamental de la République du Congo du 24 octobre 1997', 'target' => 'Constitution de la République du Congo du 15 mars 1992', 'type' => 'ABROGE', 'date' => '1997-10-24', 'commentaire' => "Guerre civile de 1997 : Denis Sassou N'Guesso promulgue l'Acte fondamental à la chute de Pascal Lissouba."],
        ['source' => 'Constitution de la République du Congo du 20 janvier 2002', 'target' => 'Acte fondamental de la République du Congo du 24 octobre 1997', 'type' => 'ABROGE', 'date' => '2002-01-20', 'commentaire' => "Constitution de la Quatrième République, adoptée par référendum le 20 janvier 2002 à l'issue de la période de transition."],
        ['source' => 'Republique du Congo Constitution 2015', 'target' => 'Constitution de la République du Congo du 20 janvier 2002', 'type' => 'ABROGE', 'date' => '2015-10-25', 'commentaire' => 'Nouvelle Constitution adoptée par référendum le 25 octobre 2015.'],
    ];

    protected $signature = 'mibeko:proposer-statuts-constitutionnels
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--out-statuts= : Fichier JSON de sortie pour les statuts (défaut : storage/app/statuts-constitutionnels-<date>.json)}
        {--out-relations= : Fichier JSON de sortie pour les relations (défaut : storage/app/relations-constitutionnelles-<date>.json)}';

    protected $description = 'Propose le statut et la généalogie des textes constitutionnels non vérifiés (mibeko-dashboard#31).';

    public function handle(): int
    {
        $db = DB::connection((string) $this->option('connection'));

        $idsParTitre = $this->resoudreTousLesTitres($db);
        if ($idsParTitre === null) {
            return self::FAILURE;
        }

        $statuts = [];
        foreach (self::STATUTS as $titre => $statut) {
            $statuts[] = [
                'id' => $idsParTitre[$titre],
                'document' => mb_strimwidth($titre, 0, 60, '…'),
                'motif' => 'Classement constitutionnel (mibeko-dashboard#31)',
                'statut' => $statut,
            ];
        }

        $relations = [];
        foreach (self::RELATIONS as $r) {
            $relations[] = [
                'source_doc_id' => $idsParTitre[$r['source']],
                'target_doc_id' => $idsParTitre[$r['target']],
                'relation_type' => $r['type'],
                'effective_date' => $r['date'],
                'commentaire' => $r['commentaire'],
                'document' => mb_strimwidth($r['source'], 0, 35, '…').' → '.mb_strimwidth($r['target'], 0, 35, '…'),
            ];
        }

        $cheminStatuts = (string) ($this->option('out-statuts')
            ?: storage_path('app/statuts-constitutionnels-'.now()->format('Ymd-His').'.json'));
        $cheminRelations = (string) ($this->option('out-relations')
            ?: storage_path('app/relations-constitutionnelles-'.now()->format('Ymd-His').'.json'));

        file_put_contents($cheminStatuts, json_encode($statuts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($cheminRelations, json_encode($relations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info(sprintf('%d statut(s) à corriger → %s', count($statuts), $cheminStatuts));
        $this->info(sprintf('%d relation(s) à créer → %s', count($relations), $cheminRelations));

        $this->newLine();
        $this->warn('AUCUNE ÉCRITURE — à relire, puis :');
        $this->line("  php artisan mibeko:corriger-statut-document --mapping={$cheminStatuts} --execute");
        $this->line("  php artisan mibeko:creer-relations-documents --mapping={$cheminRelations} --execute");

        return self::SUCCESS;
    }

    /**
     * Résout chaque titre exact utilisé ci-dessus en ID réel, et échoue
     * bruyamment si un titre ne trouve pas exactement une correspondance —
     * un texte constitutionnel a trop de conséquences pour tolérer une
     * résolution ambiguë ou silencieusement vide.
     *
     * @return array<string, string>|null
     */
    private function resoudreTousLesTitres(mixed $db): ?array
    {
        $titres = array_unique([
            ...array_keys(self::STATUTS),
            ...array_map(fn ($r) => $r['source'], self::RELATIONS),
            ...array_map(fn ($r) => $r['target'], self::RELATIONS),
        ]);

        $resolus = [];
        $enErreur = false;

        foreach ($titres as $titre) {
            $lignes = $db->table('legal_documents')
                ->whereNull('deleted_at')
                ->where('curation_status', 'published')
                ->where('titre_officiel', '=', $titre)
                ->get(['id']);

            if ($lignes->count() !== 1) {
                $enErreur = true;
                $this->error(sprintf(
                    '%d correspondance(s) pour « %s » — attendu exactement 1.',
                    $lignes->count(),
                    mb_strimwidth($titre, 0, 80, '…'),
                ));

                continue;
            }

            $resolus[$titre] = (string) $lignes->first()->id;
        }

        return $enErreur ? null : $resolus;
    }
}
