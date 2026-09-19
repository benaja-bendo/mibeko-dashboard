<?php

namespace App\Console\Commands;

use App\Models\CurationFlag;
use App\Services\Curation\NumeroActeExtractor;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Recense les défauts de CITATION du corpus (lecture seule) : les textes dont
 * la référence est introuvable, et ceux qui partagent leur citation avec un
 * autre.
 *
 * Depuis la décision du 19/09/2026, la citation d'un acte — type, numéro,
 * date de signature — porte son identité publique : c'est d'elle que dérivera
 * l'URL canonique (dashboard#156). Deux défauts empêchent cette bascule, et
 * aucun n'était signalé nulle part :
 *
 * 1. **Référence introuvable** — 102 textes publiés n'avaient ni numéro ni
 *    date le 19/09 (avis, rectificatifs, règlements intérieurs, quelques
 *    décrets et arrêtés au titre mutilé). Ils gardent leur slug actuel, et la
 *    curation doit retrouver leur référence dans le corps de l'acte ou au JO —
 *    d'où un signalement, pour que ce ne soit pas une dette invisible.
 * 2. **Citation partagée** — deux textes publiés avec le même (type, numéro,
 *    date) réclameraient la même URL. Six cas le 19/09 : cinq doublons à
 *    fusionner, un légitime (le décret 2025-279 et ses statuts annexés, qui
 *    devront être nommés). La régénération de slug refusera de tourner tant
 *    qu'une collision subsiste : mieux vaut les traiter avant.
 *
 * Pourquoi une commande à part, et pas un détecteur de plus dans
 * `JeuDeDetecteurs` : toute évolution de ce jeu incrémente sa version et
 * DÉCLASSE tous les runs de conformité antérieurs (protocole de validation,
 * étape 6 — registre #23). Ces deux contrôles servent un chantier d'identité,
 * pas la mesure de conformité du corpus ; les coupler ferait payer une
 * re-certification complète du fonds à chaque retouche de l'un d'eux. Même
 * doctrine que `mibeko:detecter-defauts-titres`.
 *
 * N'ÉCRIT RIEN. Avec `--lot=`, prépare un lot Classe 1 `creer_signalements`
 * pour la file d'opérations, que l'humain valide au clavier dans son terminal
 * (`php artisan mibeko:operations --watch`, `docs/infra/production.md` § 6 bis).
 *
 *   php artisan mibeko:detecter-citations --connection=pgsql_prod_ro
 *   php artisan mibeko:detecter-citations --json=storage/app/citations.json --lot=
 */
class DetecterCitationsCommand extends Command
{
    /**
     * Version du jeu de contrôles. Toute modification d'une condition
     * ci-dessous l'incrémente — même règle que
     * `DetecterDefautsTitresCommand`, pour que deux mesures portant le même
     * numéro soient comparables.
     */
    public const VERSION = '1.0.0';

    /** Référence de l'acte absente de son titre, et absente de la base. */
    public const TYPE_REFERENCE_INTROUVABLE = 'reference_introuvable';

    /** Deux textes vivants réclament la même citation, donc la même URL. */
    public const TYPE_DOUBLON_CITATION = 'doublon_citation';

    protected $signature = 'mibeko:detecter-citations
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--statut=published : Restreindre à un curation_status (vide = tous)}
        {--json= : Écrit le rapport détaillé dans ce fichier}
        {--lot= : Prépare un lot Classe 1 creer_signalements (défaut : storage/app/operations/pending/<date>-citations.json)}
        {--limit=0 : Limite le nombre de signalements préparés (0 = tous)}';

    protected $description = 'Recense, sans rien écrire, les références introuvables et les citations partagées.';

    public function handle(NumeroActeExtractor $extracteur): int
    {
        $connexion = (string) $this->option('connection');
        $db = DB::connection($connexion);

        $colonneExiste = $db->selectOne(
            'select 1 as presente from information_schema.columns
             where table_name = ? and column_name = ?',
            ['legal_documents', 'numero_acte'],
        ) !== null;

        $requete = $db->table('legal_documents')
            ->whereNull('deleted_at')
            ->select(array_filter([
                'id',
                'slug',
                'titre_officiel',
                'type_code',
                'date_signature',
                'curation_status',
                $colonneExiste ? 'numero_acte' : null,
            ]))
            ->orderBy('titre_officiel');

        $statut = (string) $this->option('statut');
        if ($statut !== '') {
            $requete->where('curation_status', $statut);
        }

        $documents = $requete->get();

        $sansReference = [];
        $fauxActes = 0;
        $parCitation = [];

        foreach ($documents as $document) {
            // Le numéro déjà stocké fait foi ; à défaut — corpus pas encore
            // numéroté, ou connexion pas encore migrée — on retombe sur celui
            // que le titre donne, pour que ce contrôle soit utilisable AVANT
            // la campagne comme après.
            $numero = $colonneExiste ? $document->numero_acte : null;
            $extrait = $numero === null ? $extracteur->extraire($document->titre_officiel) : null;
            $numero ??= $extrait['numero'] ?? null;

            if ($numero === null) {
                // Un titre non capitalisé n'est pas un acte sans référence :
                // c'est un fragment de phrase promu en document, dont la
                // remédiation est une fusion. Déjà couvert par `PseudoTitre`.
                if (($extrait['motif_rejet'] ?? null) === 'titre_non_capitalise') {
                    $fauxActes++;

                    continue;
                }

                $sansReference[] = [
                    'id' => $document->id,
                    'slug' => $document->slug,
                    'titre_officiel' => $document->titre_officiel,
                    'curation_status' => $document->curation_status,
                    'date_signature' => $document->date_signature,
                    'motif' => $extrait['motif_rejet'] ?? 'numero_absent',
                ];

                continue;
            }

            // Citation insensible à la casse : « 80-550/ETR » et
            // « 80-550/etr » désignent le même acte, et la colonne conserve
            // la casse imprimée au JO plutôt que de la rabattre à l'écriture.
            $citation = implode('|', [
                (string) $document->type_code,
                mb_strtolower($numero),
                (string) $document->date_signature,
            ]);

            $parCitation[$citation][] = [
                'id' => $document->id,
                'slug' => $document->slug,
                'titre_officiel' => $document->titre_officiel,
                'curation_status' => $document->curation_status,
                'type_code' => $document->type_code,
                'numero_acte' => $numero,
                'date_signature' => $document->date_signature,
            ];
        }

        $doublons = array_values(array_filter($parCitation, fn (array $groupe) => count($groupe) > 1));

        $ouverts = $this->signalementsDejaOuverts($db);

        $this->afficherLeResume($documents->count(), $sansReference, $doublons, $fauxActes, $ouverts);
        $this->ecrireLeRapport($sansReference, $doublons);
        $this->preparerLeLot($sansReference, $doublons, $ouverts);

        return self::SUCCESS;
    }

    /**
     * Signalements de citation déjà ouverts, par document — pour ne pas
     * proposer deux fois le même à la file d'opérations. Un signalement
     * RÉSOLU n'est pas repris ici : s'il ressort, c'est que le défaut est
     * revenu, et il mérite un nouveau signalement.
     *
     * @return array<string, list<string>> document_id => types déjà ouverts
     */
    private function signalementsDejaOuverts(Connection $db): array
    {
        $ouverts = [];

        $lignes = $db->table('curation_flags')
            ->whereIn('type_probleme', [self::TYPE_REFERENCE_INTROUVABLE, self::TYPE_DOUBLON_CITATION])
            ->where('resolved', false)
            ->whereNotNull('document_id')
            ->select('document_id', 'type_probleme')
            ->get();

        foreach ($lignes as $ligne) {
            $ouverts[$ligne->document_id][] = $ligne->type_probleme;
        }

        return $ouverts;
    }

    /**
     * @param  list<array<string, mixed>>  $sansReference
     * @param  list<list<array<string, mixed>>>  $doublons
     * @param  array<string, list<string>>  $ouverts
     */
    private function afficherLeResume(
        int $total,
        array $sansReference,
        array $doublons,
        int $fauxActes,
        array $ouverts,
    ): void {
        $documentsEnDoublon = array_sum(array_map('count', $doublons));

        $this->info('Contrôle de citation v'.self::VERSION." — {$total} document(s) examinés.");
        $this->newLine();

        $this->table(['Défaut', 'Documents'], [
            ['Référence introuvable (ni en base, ni dans le titre)', count($sansReference)],
            ['Citation partagée avec un autre texte', $documentsEnDoublon.' ('.count($doublons).' citation(s))'],
            ['Fragments de phrase promus en documents (hors périmètre)', $fauxActes],
        ]);

        if ($doublons !== []) {
            $this->newLine();
            $this->line('Citations partagées :');

            foreach (array_slice($doublons, 0, 15) as $groupe) {
                $premier = $groupe[0];
                $this->line(sprintf('  · %s n° %s du %s — %d documents',
                    (string) $premier['type_code'],
                    (string) $premier['numero_acte'],
                    (string) ($premier['date_signature'] ?? 'date inconnue'),
                    count($groupe),
                ));

                foreach ($groupe as $document) {
                    $this->line(sprintf('      %s  %s', $document['id'],
                        mb_strimwidth((string) $document['titre_officiel'], 0, 60, '…')));
                }
            }
        }

        if ($ouverts !== []) {
            $this->newLine();
            $this->line(count($ouverts).' document(s) portent déjà un signalement de citation ouvert — non reproposés.');
        }

        $this->newLine();
        $this->warn('AUCUNE ÉCRITURE. Les signalements se créent par la file d\'opérations Classe 1, '
            .'validés au clavier : php artisan mibeko:operations --watch');
    }

    /**
     * @param  list<array<string, mixed>>  $sansReference
     * @param  list<list<array<string, mixed>>>  $doublons
     */
    private function ecrireLeRapport(array $sansReference, array $doublons): void
    {
        $chemin = (string) $this->option('json');

        if ($chemin === '') {
            return;
        }

        file_put_contents($chemin, json_encode([
            'version' => self::VERSION,
            'mesure_du' => now()->toIso8601String(),
            'connexion' => $this->option('connection'),
            'statut' => $this->option('statut'),
            'reference_introuvable' => $sansReference,
            'doublons_citation' => array_values($doublons),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->line("Rapport détaillé écrit dans <fg=cyan>{$chemin}</>.");
    }

    /**
     * Prépare le lot Classe 1 que l'humain validera au clavier.
     *
     * `expected_rows` est l'effectif exact annoncé : la file annule la
     * transaction et s'arrête net si l'écriture en touche un autre nombre
     * (§ 6 bis). C'est pourquoi le lot est écrit APRÈS déduplication des
     * signalements déjà ouverts, et jamais avant.
     *
     * @param  list<array<string, mixed>>  $sansReference
     * @param  list<list<array<string, mixed>>>  $doublons
     * @param  array<string, list<string>>  $ouverts
     */
    private function preparerLeLot(array $sansReference, array $doublons, array $ouverts): void
    {
        if ($this->option('lot') === null) {
            return;
        }

        $signalements = [];

        foreach ($sansReference as $document) {
            if (in_array(self::TYPE_REFERENCE_INTROUVABLE, $ouverts[$document['id']] ?? [], true)) {
                continue;
            }

            $signalements[] = [
                'document_id' => $document['id'],
                'type_probleme' => self::TYPE_REFERENCE_INTROUVABLE,
                'severity' => CurationFlag::SEVERITY_WARNING,
                'description' => $this->descriptionReferenceIntrouvable($document),
            ];
        }

        foreach ($doublons as $groupe) {
            foreach ($groupe as $document) {
                if (in_array(self::TYPE_DOUBLON_CITATION, $ouverts[$document['id']] ?? [], true)) {
                    continue;
                }

                $signalements[] = [
                    'document_id' => $document['id'],
                    'type_probleme' => self::TYPE_DOUBLON_CITATION,
                    'severity' => CurationFlag::SEVERITY_BLOCKING,
                    'description' => $this->descriptionDoublon($groupe, $document),
                ];
            }
        }

        $limite = (int) $this->option('limit');
        if ($limite > 0) {
            $signalements = array_slice($signalements, 0, $limite);
        }

        if ($signalements === []) {
            $this->newLine();
            $this->line('Aucun signalement à créer : tout est déjà signalé, ou rien n\'est à signaler.');

            return;
        }

        $chemin = (string) ($this->option('lot')
            ?: storage_path('app/operations/pending/'.now()->format('Ymd-His').'-citations.json'));

        if (! is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0755, true);
        }

        file_put_contents($chemin, json_encode([
            'campagne' => 'citations-'.now()->format('Y-m-d'),
            'operation' => 'creer_signalements',
            'expected_rows' => count($signalements),
            'dry_run_output' => sprintf(
                'mibeko:detecter-citations v%s sur %s (%s) : %d référence(s) introuvable(s), '
                .'%d document(s) en citation partagée — aucun doublon avec un signalement déjà ouvert.',
                self::VERSION,
                (string) $this->option('connection'),
                (string) ($this->option('statut') ?: 'tous statuts'),
                count($sansReference),
                array_sum(array_map('count', $doublons)),
            ),
            'rollback' => [
                'description' => 'Les signalements créés se résolvent en masse (source human, '
                    .'type_probleme reference_introuvable / doublon_citation) sans toucher aux documents.',
                'moyen' => 'Lot Classe 1 `resoudre_signalements` sur les identifiants créés, ou la console '
                    .'des signalements de l\'espace admin.',
            ],
            'params' => ['signalements' => $signalements],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info(count($signalements).' signalement(s) préparés dans '.$chemin);
        $this->line('À valider au clavier : <fg=cyan>php artisan mibeko:operations --watch</>');
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function descriptionReferenceIntrouvable(array $document): string
    {
        $date = $document['date_signature'] === null
            ? 'Aucune date de signature non plus'
            : 'La date de signature ('.$document['date_signature'].') est connue';

        return 'Référence introuvable : le titre officiel ne porte aucun numéro d\'acte lisible, et la colonne '
            ."numero_acte est vide. {$date}. Le numéro est à retrouver dans le corps de l'acte ou au Journal "
            .'officiel, puis à saisir dans l\'éditeur (provenance « manuel »). Tant qu\'il manque, ce texte garde '
            .'son slug actuel comme URL canonique (décision du 19/09/2026).';
    }

    /**
     * @param  list<array<string, mixed>>  $groupe
     * @param  array<string, mixed>  $document
     */
    private function descriptionDoublon(array $groupe, array $document): string
    {
        $autres = array_values(array_filter($groupe, fn (array $frere) => $frere['id'] !== $document['id']));

        $liste = implode(' ; ', array_map(
            fn (array $frere) => $frere['id'].' (« '.mb_strimwidth((string) $frere['titre_officiel'], 0, 80, '…').' »)',
            $autres,
        ));

        return sprintf(
            'Citation partagée : %s n° %s du %s désigne aussi %s. Deux textes qui partagent leur citation '
            .'réclameraient la même URL canonique — à fusionner s\'il s\'agit d\'un doublon, ou à nommer '
            .'explicitement s\'il s\'agit d\'une annexe (statuts annexés à un décret, par exemple). '
            .'La régénération des slugs (dashboard#156) refuse de tourner tant que la collision subsiste.',
            (string) $document['type_code'],
            (string) $document['numero_acte'],
            (string) ($document['date_signature'] ?? 'date inconnue'),
            $liste,
        );
    }
}
