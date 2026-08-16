<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recense les défauts d'intitulé et de structure du corpus (lecture seule).
 *
 * Né du constat du 16/08/2026 : le corpus de production portait 175 documents
 * défectueux — dont 102 « faux actes » en brouillon — sans qu'un seul
 * `curation_flags` ne le signale. Les détecteurs existants ne regardaient que
 * le CONTENU des articles ; personne ne regardait l'intitulé, qui est pourtant
 * ce que l'utilisateur lit en premier dans une liste de résultats.
 *
 * Trois enseignements de la campagne de mesure, qui expliquent la forme des
 * conditions ci-dessous — les défaire, c'est refaire les mêmes faux positifs :
 *
 * 1. **Un en-tête d'acte du Journal officiel est toujours capitalisé.** Un
 *    intitulé FLUX qui commence par une minuscule (« décret en Conseil des
 *    ministres. », « loi de finances de l'année. ») n'est pas un intitulé
 *    abîmé : c'est un fragment de phrase que le découpage a promu en document.
 *    Détecteur à 12/12 sur tirage aléatoire. Certains portent jusqu'à 90
 *    articles bien réels — le contenu existe, c'est la frontière du document
 *    qui est fausse, d'où une remédiation par fusion et jamais par retitrage.
 *
 * 2. **« Décret n° 2025-240 du 20 juin 2025. » n'est PAS un titre tronqué.**
 *    Les nominations paraissent au JO en « actes en abrégé » : le sommaire
 *    n'annonce que « Nomination. » et l'en-tête n'imprime aucun objet. Vérifié
 *    sur tirage aléatoire de 10 documents contre les markdowns MinerU source :
 *    9 sont fidèles à leur source. Leur recomposer un objet depuis le corps
 *    serait fabriquer un titre officiel qui n'existe pas. Ils sont donc
 *    signalés comme OBSERVATION, jamais comme défaut.
 *
 * 3. Les intitulés qui finissent sur une date mais portent déjà un objet
 *    (« … portant révision de l'article 157 de la Constitution du 25 octobre
 *    2015 ») sont corrects : d'où l'exclusion par mot-clé d'objet.
 *
 * Ne fait AUCUNE écriture. Les remédiations vivent dans des commandes
 * distinctes, une par famille, parce qu'elles n'ont ni le même canal ni la
 * même classe d'autorisation (`docs/infra/production.md` § 6).
 *
 *   php artisan mibeko:detecter-defauts-titres --connection=pgsql_prod_ro
 *   php artisan mibeko:detecter-defauts-titres --json=storage/app/defauts.json
 */
class DetecterDefautsTitresCommand extends Command
{
    /**
     * Version du jeu de détecteurs. Publier une nouvelle version DÉCLASSE les
     * documents certifiés sous une version antérieure — règle non négociable
     * du protocole de validation (`docs/pipeline/protocole-validation.md`,
     * étape 6). Toute modification d'une condition ci-dessous l'incrémente.
     */
    public const VERSION = '1.0.0';

    /**
     * Mots qui ouvrent l'objet d'un acte. Leur présence prouve que l'intitulé
     * dit ce que l'acte fait, donc qu'il n'est pas tronqué.
     */
    private const OBJET = '(portant|fixant|relatif|relative|modifiant|complétant|completant|instituant|'
        .'créant|creant|approuvant|autorisant|abrogeant|nommant|organisant|déterminant|determinant|'
        .'prescrivant|accordant|réglementant|reglementant|convoquant|conférant|conferant|prorogeant|'
        .'ratifiant|promulguant|déclarant|declarant|érigeant|erigeant|attribuant|rendant|désignant|'
        .'designant|homologuant|renouvelant|prolongeant|supprimant|transférant|transferant)';

    /**
     * Les familles, dans l'ordre où elles doivent être traitées : une structure
     * fausse se répare avant un intitulé, sans quoi on retitre proprement un
     * document qui n'aurait jamais dû exister.
     *
     * @return array<string, array{libelle: string, gravite: string, classe: int|null, remediation: string, condition: string}>
     */
    private function familles(): array
    {
        $objet = self::OBJET;

        return [
            'B1_faux_acte' => [
                'libelle' => 'Faux acte : un fragment de phrase promu en document',
                'gravite' => 'blocking',
                'classe' => 1,
                'remediation' => "Fusionner dans l'acte précédent du JO — jamais retitrer",
                'condition' => "document_role = 'FLUX' and titre_officiel ~ '^[a-zà-öø-ÿ]'",
            ],
            'B2_zero_article' => [
                'libelle' => 'Coquille vide : aucun article',
                'gravite' => 'blocking',
                'classe' => 1,
                'remediation' => 'Réingérer depuis la source, ou retirer',
                'condition' => 'not exists (select 1 from articles a
                    where a.document_id = legal_documents.id and a.deleted_at is null)',
            ],
            'B3_signature_seule' => [
                'libelle' => 'Acte fantôme : le seul article est une SIGNATURE',
                'gravite' => 'blocking',
                'classe' => 1,
                'remediation' => "Fusionner dans l'acte précédent — c'est son bloc de signature",
                'condition' => "exists (select 1 from articles a
                        where a.document_id = legal_documents.id and a.deleted_at is null)
                    and not exists (select 1 from articles a
                        where a.document_id = legal_documents.id and a.deleted_at is null
                          and a.numero_article <> 'SIGNATURE')",
            ],
            'B4_latex' => [
                'libelle' => "Résidu LaTeX ou marqueur technique dans l'intitulé",
                'gravite' => 'warning',
                'classe' => 1,
                'remediation' => 'Nettoyage outillé du titre (mibeko:reconstruire-titres)',
                'condition' => "position('$' in titre_officiel) > 0
                    or titre_officiel like '%mathrm%' or titre_officiel like '%pmb%'
                    or titre_officiel like '%MIBEKO_PAGE%' or titre_officiel like '%<!--%'",
            ],
            'B5_page_collee' => [
                'libelle' => "Numéro de page du JO collé à l'intitulé",
                'gravite' => 'warning',
                'classe' => null,
                'remediation' => 'Retirer le nombre final (mécanique, fidèle)',
                'condition' => "btrim(titre_officiel) ~ ' [0-9]{1,4}$'
                    and btrim(titre_officiel) !~ ' (1[6-9][0-9]{2}|20[0-9]{2})$'",
            ],
            'B6_cesure' => [
                'libelle' => "Césure OCR jamais recollée dans l'intitulé (« comi- té »)",
                'gravite' => 'warning',
                'classe' => null,
                'remediation' => 'Dé-hyphénation mécanique',
                'condition' => "titre_officiel ~ '[a-zà-öø-ÿ]- [a-zà-öø-ÿ]'",
            ],
            'P1_corps_dans_titre' => [
                'libelle' => "Le corps de l'acte a été avalé dans l'intitulé",
                'gravite' => 'blocking',
                'classe' => 2,
                'remediation' => "Tronquer l'intitulé après la date (le corps est déjà dans les articles)",
                'condition' => "length(titre_officiel) > 170 and titre_officiel ~ '[a-zà-ÿ][.] [A-ZÀ-Ý]'",
            ],
            'P3_jo_entier' => [
                'libelle' => 'Le JO entier ingéré comme un document unique',
                'gravite' => 'blocking',
                'classe' => 2,
                'remediation' => 'Réingérer et redécouper le JO — aucun patch de titre ne suffit',
                'condition' => "titre_officiel ~* '^journal officiel n'",
            ],
            'P4_sommaire' => [
                'libelle' => 'Ligne de sommaire ingérée comme document (points de conduite)',
                'gravite' => 'blocking',
                'classe' => 2,
                'remediation' => 'Retirer : le document réel existe ailleurs dans le JO',
                'condition' => "position('...' in titre_officiel) > 0",
            ],
            'P7_stock_minuscule' => [
                'libelle' => 'Code STOCK au titre bâclé (minuscules, accents manquants)',
                'gravite' => 'info',
                'classe' => 2,
                'remediation' => 'Retitrage éditorial manuel',
                'condition' => "document_role = 'STOCK' and titre_officiel ~ '^[a-zà-öø-ÿ]'",
            ],
            'I1_acte_en_abrege' => [
                'libelle' => "OBSERVATION — acte en abrégé : le JO n'imprime aucun objet",
                'gravite' => 'info',
                'classe' => null,
                'remediation' => "AUCUNE : l'intitulé est fidèle à la source. Enjeu produit, pas de données",
                'condition' => "titre_officiel ~* 'n° *[0-9]'
                    and btrim(titre_officiel) ~* '(du|le) [0-9]{1,2}(er)? [a-zéûà]+ (1[6-9][0-9]{2}|20[0-9]{2})[.,]?$'
                    and titre_officiel !~* '$objet'",
            ],
        ];
    }

    protected $signature = 'mibeko:detecter-defauts-titres
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--statut= : Restreindre à un curation_status (draft, published…)}
        {--famille=* : Restreindre à certaines familles (code exact)}
        {--exemples=3 : Nombre d\'exemplaires affichés par famille}
        {--json= : Écrit le détail (identifiants compris) dans ce fichier}';

    protected $description = "Recense les défauts d'intitulé et de structure du corpus, sans rien écrire.";

    public function handle(): int
    {
        $db = DB::connection((string) $this->option('connection'));
        $statut = $this->option('statut');
        $retenues = (array) $this->option('famille');
        $nbExemples = max(0, (int) $this->option('exemples'));

        $this->components->info(sprintf(
            'Détecteurs v%s · connexion %s%s',
            self::VERSION,
            (string) $this->option('connection'),
            $statut ? " · statut {$statut}" : '',
        ));

        $lignes = [];
        $detail = [];
        $union = [];

        foreach ($this->familles() as $code => $famille) {
            if ($retenues !== [] && ! in_array($code, $retenues, true)) {
                continue;
            }

            $requete = $db->table('legal_documents')
                ->whereNull('deleted_at')
                ->whereRaw('('.$famille['condition'].')')
                ->select(['id', 'titre_officiel', 'curation_status', 'document_role', 'slug']);

            if (is_string($statut) && $statut !== '') {
                $requete->where('curation_status', $statut);
            }

            $documents = $requete->orderBy('curation_status')->orderBy('titre_officiel')->get();

            $brouillons = $documents->where('curation_status', 'draft')->count();
            $publies = $documents->where('curation_status', 'published')->count();

            $lignes[] = [
                $code,
                mb_strimwidth($famille['libelle'], 0, 46, '…'),
                $famille['gravite'],
                $famille['classe'] ?? '—',
                (string) $brouillons,
                (string) $publies,
                (string) $documents->count(),
            ];

            $detail[$code] = [
                'libelle' => $famille['libelle'],
                'gravite' => $famille['gravite'],
                'classe_ecriture' => $famille['classe'],
                'remediation' => $famille['remediation'],
                'condition' => preg_replace('/\s+/', ' ', $famille['condition']),
                'total' => $documents->count(),
                'draft' => $brouillons,
                'published' => $publies,
                'documents' => $documents->map(fn ($d) => [
                    'id' => $d->id,
                    'titre_officiel' => $d->titre_officiel,
                    'curation_status' => $d->curation_status,
                    'document_role' => $d->document_role,
                    'slug' => $d->slug,
                ])->values()->all(),
            ];

            // L'observation ne compte pas comme un défaut : elle ne doit pas
            // gonfler l'union, sans quoi le chiffre annoncé cesse d'être vrai.
            if (! str_starts_with($code, 'I')) {
                foreach ($documents as $document) {
                    $union[$document->id] = $document->curation_status;
                }
            }

            if ($nbExemples > 0 && $documents->isNotEmpty()) {
                $this->newLine();
                $this->line("  <fg=yellow>{$code}</> — {$famille['libelle']}");
                foreach ($documents->take($nbExemples) as $document) {
                    $this->line(sprintf(
                        '    [%s] %-9s %s',
                        substr((string) $document->id, 0, 8),
                        $document->curation_status,
                        mb_strimwidth((string) $document->titre_officiel, 0, 96, '…'),
                    ));
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Famille', 'Défaut', 'Gravité', 'Classe', 'Brouillon', 'Publié', 'Total'],
            $lignes,
        );

        $defautsBrouillon = count(array_filter($union, fn ($s) => $s === 'draft'));
        $defautsPublies = count(array_filter($union, fn ($s) => $s === 'published'));

        $this->components->twoColumnDetail(
            '<fg=cyan>Documents distincts en défaut</>',
            sprintf('%d (brouillon %d · publié %d)', count($union), $defautsBrouillon, $defautsPublies),
        );

        if ($chemin = $this->option('json')) {
            file_put_contents((string) $chemin, json_encode([
                'version_detecteurs' => self::VERSION,
                'mesure_le' => now()->toIso8601String(),
                'connexion' => (string) $this->option('connection'),
                'union_documents_en_defaut' => count($union),
                'familles' => $detail,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $this->components->twoColumnDetail('<fg=cyan>Détail écrit dans</>', (string) $chemin);
        }

        return self::SUCCESS;
    }
}
