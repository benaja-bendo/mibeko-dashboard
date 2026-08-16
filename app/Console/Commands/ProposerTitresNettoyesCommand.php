<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Propose des intitulés nettoyés — par RETRAIT seulement, jamais par ajout.
 *
 * Complète `mibeko:proposer-titres` (qui, lui, RECOLLE la suite manquante d'un
 * titre coupé sur « … portant ») : ici on ne va rien chercher ailleurs, on
 * enlève ce qui n'aurait jamais dû entrer dans le champ. Quatre pollutions
 * mesurées en production le 16/08/2026, sur 64 documents :
 *
 * - **le corps de l'acte** avalé derrière la date (« Décret n° 2025-283 du
 *   2 juillet 2025 M. TABAKA (Mexan Guillaume) est nommé inspec- teur
 *   général… ») — 15 documents, tous PUBLIÉS, donc lus en ligne aujourd'hui ;
 * - **le numéro de page du JO** collé en fin d'intitulé (« … Ordre du Mérite
 *   Congolais. 34 ») — 38 documents ;
 * - **une césure de fin de ligne** jamais recollée (« comi- té ») — 3 ;
 * - **un résidu LaTeX** de la conversion OCR (« Avis $\pmb{\mathrm{n}}^{\circ}$
 *   338 ») — 8.
 *
 * L'INVARIANT DE SÛRETÉ, vérifié sur chaque proposition avant de la retenir :
 * le titre proposé doit se déduire du titre actuel par suppression de
 * caractères et recollage de césures — sauf pour les motifs LaTeX, dont la
 * table de conversion est close et explicite ci-dessous. Toute proposition qui
 * échoue à cette vérification est écartée et comptée. C'est ce qui distingue
 * ce nettoyage d'une réécriture : aucun mot ne peut apparaître qui n'était pas
 * déjà là. Un titre juridique inventé, même plausible, est une falsification
 * (`docs/pipeline/correction-depuis-la-source.md`, contrainte n° 1).
 *
 * N'écrit rien. La sortie alimente les deux canaux d'écriture existants, selon
 * le statut du document — jamais l'inverse, le slug d'un document publié ne
 * devant pas bouger :
 *
 *   php artisan mibeko:proposer-titres-nettoyes --out=storage/app/nettoyage.json
 *   php artisan mibeko:corriger-titres-publies --liste=… --execute   # publiés
 *   php artisan mibeko:corriger-titres-jo      --mapping=… --execute # brouillons
 */
class ProposerTitresNettoyesCommand extends Command
{
    /**
     * Où s'arrête l'intitulé quand le corps de l'acte l'a débordé.
     *
     * Le repère n'est PAS la date seule : après la date vient très souvent
     * l'OBJET, qui fait légitimement partie de l'intitulé (« … du 21 août 2025
     * portant attributions du comité… »). Couper à la date détruirait cet
     * objet — première version de cette commande, qui aurait amputé 36 titres
     * corrects, dont le décret n° 2025-359 donné en exemple par le fondateur.
     *
     * Le vrai repère est typographique, et il est celui du Journal officiel :
     * **l'objet prolonge le titre en minuscule** (« portant », « fixant »,
     * « relative », « créé »), tandis que **le corps ouvre une phrase en
     * majuscule** (« M. TABAKA … est nommé », « Sont nommés … », « En
     * application des dispositions … »). On ne coupe donc qu'à une majuscule
     * suivant la date, et seulement sur un intitulé déjà anormalement long.
     */
    private const FIN_INTITULE = '/^(.{0,220}?\b(?:du|le)\s+\d{1,2}(?:er)?\s+'
        .'(?:janvier|février|fevrier|mars|avril|mai|juin|juillet|août|aout|septembre|octobre|novembre|décembre|decembre)'
        .'\s+(?:1[6-9]\d{2}|20\d{2}))\s*[.,]?\s+(?=[A-ZÀ-Ý])/u';

    /**
     * En deçà, un intitulé long ne l'est pas assez pour qu'on soupçonne le
     * corps de l'acte d'y être entré — seuil du détecteur P1_corps_dans_titre.
     */
    private const LONGUEUR_SUSPECTE = 170;

    /**
     * Table de conversion LaTeX close : chaque entrée a été vue en production.
     * Rien n'est deviné — un motif absent de cette table fait écarter le
     * document plutôt qu'appliquer une transformation approximative.
     *
     * @var array<string, string>
     */
    private const LATEX = [
        '$\pmb{\mathrm{n}}^{\circ}$' => 'n°',
        '$\mathbf{n}^{\circ}$' => 'n°',
        '$\mathrm{n}^{\circ}$' => 'n°',
        '$\mathbf{n}^{\mathrm{o}}$' => 'n°',
        '$n^{\circ}$' => 'n°',
        '$^{\circ}$' => '°',
        '$\circ$' => '°',
    ];

    protected $signature = 'mibeko:proposer-titres-nettoyes
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--statut= : Restreindre à un curation_status (draft, published…)}
        {--out= : Fichier JSON de sortie (défaut : storage/app/titres-nettoyes-<date>.json)}';

    protected $description = 'Propose, sans rien écrire, des intitulés nettoyés par retrait pur.';

    public function handle(): int
    {
        $db = DB::connection((string) $this->option('connection'));

        $requete = $db->table('legal_documents')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereRaw("length(titre_officiel) > 170 and titre_officiel ~ '[a-zà-ÿ][.] [A-ZÀ-Ý]'")
                    ->orWhereRaw("btrim(titre_officiel) ~ ' [0-9]{1,4}$'
                        and btrim(titre_officiel) !~ ' (1[6-9][0-9]{2}|20[0-9]{2})$'")
                    ->orWhereRaw("titre_officiel ~ '[a-zà-öø-ÿ]- [a-zà-öø-ÿ]'")
                    ->orWhereRaw("position('$' in titre_officiel) > 0");
            })
            ->select(['id', 'slug', 'titre_officiel', 'curation_status', 'document_role']);

        if (is_string($statut = $this->option('statut')) && $statut !== '') {
            $requete->where('curation_status', $statut);
        }

        $propositions = [];
        $ecartes = [];

        foreach ($requete->orderBy('curation_status')->orderBy('titre_officiel')->get() as $document) {
            $actuel = (string) $document->titre_officiel;
            [$propose, $operations] = $this->nettoyer($actuel);

            if ($operations === [] || $propose === $actuel) {
                continue;
            }

            if (! $this->estDeductible($actuel, $propose)) {
                $ecartes[] = [
                    'id' => $document->id,
                    'titre_actuel' => $actuel,
                    'titre_rejete' => $propose,
                    'motif' => "le titre proposé n'est pas déductible de l'actuel par retrait",
                ];

                continue;
            }

            $propositions[] = [
                'id' => $document->id,
                'slug' => $document->slug,
                'curation_status' => $document->curation_status,
                'titre_actuel' => $actuel,
                'titre' => $propose,
                'operations' => $operations,
                'caracteres_retires' => mb_strlen($actuel) - mb_strlen($propose),
                'confiance' => in_array('corps_retire', $operations, true) && mb_strlen($propose) < 25
                    ? 'a verifier'
                    : 'haute',
            ];
        }

        $chemin = (string) ($this->option('out')
            ?: storage_path('app/titres-nettoyes-'.now()->format('Ymd-His').'.json'));

        file_put_contents($chemin, json_encode([
            'propositions' => $propositions,
            'ecartes' => $ecartes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->table(
            ['Statut', 'Opérations', 'Avant', 'Après'],
            collect($propositions)->take(12)->map(fn ($p) => [
                $p['curation_status'],
                implode('+', $p['operations']),
                mb_strimwidth($p['titre_actuel'], 0, 44, '…'),
                mb_strimwidth($p['titre'], 0, 44, '…'),
            ])->all(),
        );

        $parOperation = collect($propositions)
            ->flatMap(fn ($p) => $p['operations'])
            ->countBy()
            ->map(fn ($n, $op) => "{$op}={$n}")
            ->implode(' · ');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Propositions</>', (string) count($propositions));
        $this->components->twoColumnDetail('<fg=cyan>Par opération</>', $parOperation ?: '—');
        $this->components->twoColumnDetail(
            '<fg=cyan>Publiés / brouillons</>',
            collect($propositions)->where('curation_status', 'published')->count()
            .' / '.collect($propositions)->where('curation_status', 'draft')->count(),
        );
        $this->components->twoColumnDetail('<fg=yellow>Écartés (invariant)</>', (string) count($ecartes));
        $this->components->twoColumnDetail('<fg=cyan>Écrit dans</>', $chemin);

        $this->newLine();
        $this->components->warn('Relire le fichier avant de le passer à une commande d\'écriture.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function nettoyer(string $titre): array
    {
        $operations = [];
        $resultat = $titre;

        foreach (self::LATEX as $motif => $remplacement) {
            if (str_contains($resultat, $motif)) {
                $resultat = str_replace($motif, $remplacement, $resultat);
                $operations[] = 'latex_converti';
            }
        }

        // Un « $ » encore présent signale un motif LaTeX hors table : on ne
        // devine pas, le document ressortira au prochain passage.
        if (str_contains($resultat, '$')) {
            return [$titre, []];
        }

        if (preg_match('/[a-zà-öø-ÿ]- [a-zà-öø-ÿ]/u', $resultat) === 1) {
            $resultat = preg_replace('/([a-zà-öø-ÿ])- ([a-zà-öø-ÿ])/u', '$1$2', $resultat) ?? $resultat;
            $operations[] = 'cesure_recollee';
        }

        if (mb_strlen($resultat) > self::LONGUEUR_SUSPECTE
            && preg_match(self::FIN_INTITULE, $resultat, $capture) === 1) {
            $resultat = $capture[1];
            $operations[] = 'corps_retire';
        }

        // Points de conduite d'une ligne de sommaire, puis numéro de page nu.
        $sansConduite = preg_replace('/\s*\.{3,}\s*\d{0,4}\s*$/u', '', $resultat) ?? $resultat;
        if ($sansConduite !== $resultat) {
            $resultat = $sansConduite;
            $operations[] = 'points_de_conduite_retires';
        }

        $sansPage = preg_replace('/\s+(?!1[6-9]\d{2}\b|20\d{2}\b)\d{1,4}\s*$/u', '', $resultat) ?? $resultat;
        if ($sansPage !== $resultat && mb_strlen($sansPage) > 20) {
            $resultat = $sansPage;
            $operations[] = 'page_retiree';
        }

        // Les espaces surnuméraires sont eux aussi du bruit d'OCR (« Avis  n°
        // 338 »), mais ne justifient pas à eux seuls de réécrire un titre :
        // on ne les resserre que sur un intitulé déjà touché par ailleurs.
        if ($operations !== []) {
            $resserre = preg_replace('/\s{2,}/u', ' ', $resultat) ?? $resultat;
            if ($resserre !== $resultat) {
                $resultat = $resserre;
                $operations[] = 'espaces_resserres';
            }
        }

        return [rtrim($resultat), array_values(array_unique($operations))];
    }

    /**
     * Le titre proposé est-il obtenu du titre actuel par pur retrait ?
     *
     * Comparaison sur une forme normalisée d'où l'on ôte espaces, césures et
     * conversions LaTeX : le proposé doit alors être une SOUS-SÉQUENCE de
     * l'actuel. Une sous-séquence ne peut contenir aucun caractère absent de
     * l'original, ni dans un autre ordre — c'est exactement « on n'a rien
     * inventé », rendu vérifiable.
     */
    private function estDeductible(string $actuel, string $propose): bool
    {
        $normaliser = static function (string $texte): string {
            $texte = str_replace(array_keys(self::LATEX), array_values(self::LATEX), $texte);
            $texte = preg_replace('/([a-zà-öø-ÿ])- ([a-zà-öø-ÿ])/u', '$1$2', $texte) ?? $texte;

            return preg_replace('/\s+/u', '', $texte) ?? $texte;
        };

        $source = $normaliser($actuel);
        $cible = $normaliser($propose);

        if ($cible === '' || mb_strlen($cible) > mb_strlen($source)) {
            return false;
        }

        $curseur = 0;
        $longueurSource = mb_strlen($source);

        for ($i = 0; $i < mb_strlen($cible); $i++) {
            $caractere = mb_substr($cible, $i, 1);

            while ($curseur < $longueurSource && mb_substr($source, $curseur, 1) !== $caractere) {
                $curseur++;
            }

            if ($curseur >= $longueurSource) {
                return false;
            }

            $curseur++;
        }

        return true;
    }
}
