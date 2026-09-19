<?php

namespace App\Console\Commands;

use App\Services\Curation\NumeroActeExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Propose, sans rien écrire, le numéro d'acte lisible dans chaque titre
 * officiel — la pièce manquante de la citation (type, numéro, date).
 *
 * Décision du 19/09/2026 : l'URL canonique d'un texte dérive désormais de sa
 * citation, qui ne change jamais, et non plus de son titre, que la curation
 * corrige. Mesuré en production le même jour : 810 des 1 087 textes publiés
 * portent un numéro lisible dans leur titre, mais aucun champ structuré ne le
 * porte. Cette commande les extrait ; elle n'en écrit aucun.
 *
 * Même contrat que `mibeko:proposer-libelles`, pour la même raison : un titre
 * est du texte OCR, et le numéro qu'on en tire deviendra une URL publique. Le
 * fichier produit (--out) est une table {id, numero, confiance, …} relue par
 * un humain, puis appliquée par `mibeko:appliquer-numeros`. `titre_officiel`
 * n'est jamais touché, ni ici ni là-bas.
 *
 * Ce qui ne s'extrait pas ne se force pas : les titres sans numéro, ceux dont
 * la forme n'est pas attestée, et les fragments de phrase promus en documents
 * (titre non capitalisé) sortent en compteurs de rejet, avec leur motif — ils
 * relèvent de `mibeko:detecter-citations`, qui les signale à la curation.
 *
 * Lecture seule : se connecte par défaut à `pgsql_prod_ro`.
 *
 *   php artisan mibeko:proposer-numeros --statut=published --out=/tmp/numeros.json
 */
class ProposerNumerosCommand extends Command
{
    protected $signature = 'mibeko:proposer-numeros
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--statut=published : Restreindre à un curation_status (vide = tous)}
        {--sans-numero : Ignorer les documents qui ont déjà un numéro d\'acte}
        {--confiance= : Ne retenir que cette confiance (haute, a_verifier)}
        {--out= : Fichier JSON de sortie (défaut : storage/app/numeros-proposes-<date>.json)}
        {--limit=0 : Limite le nombre de propositions écrites (0 = toutes)}';

    protected $description = 'Propose, sans rien écrire, le numéro d\'acte lisible dans le titre officiel.';

    public function handle(NumeroActeExtractor $extracteur): int
    {
        $connexion = (string) $this->option('connection');
        $db = DB::connection($connexion);

        // Mesurer la population AVANT de déployer la migration est l'ordre
        // naturel des choses — et le seul moyen de savoir si le chantier vaut
        // le déploiement. Sans ce test, la commande échouerait sur une prod
        // pas encore migrée (même garde que `mibeko:proposer-libelles`).
        $colonneExiste = $db->selectOne(
            'select 1 as presente from information_schema.columns
             where table_name = ? and column_name = ?',
            ['legal_documents', 'numero_acte'],
        ) !== null;

        if (! $colonneExiste) {
            $this->warn('La colonne `numero_acte` n\'existe pas sur la connexion '.$connexion
                .' — migration non déployée. Les propositions sont produites quand même ; '
                .'`numero_actuel` sera nul et --sans-numero est sans effet.');
            $this->newLine();
        }

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

        if ($this->option('sans-numero') && $colonneExiste) {
            $requete->whereNull('numero_acte');
        }

        $propositions = [];
        $rejets = [];

        foreach ($requete->get() as $document) {
            $extrait = $extracteur->extraire($document->titre_officiel);

            if ($extrait['motif_rejet'] !== null) {
                $rejets[$extrait['motif_rejet']] = ($rejets[$extrait['motif_rejet']] ?? 0) + 1;

                continue;
            }

            // Croisement avec les champs structurés : un titre dont le mot de
            // tête contredit `type_code`, ou dont la date contredit
            // `date_signature`, est presque toujours une phrase de corps
            // promue en titre — son numéro appartient à un AUTRE acte. Le
            // numéro est proposé quand même (le relecteur tranche), mais
            // rétrogradé « à vérifier », avec le motif.
            $alertes = [];

            if ($extracteur->typeContredit($document->titre_officiel, $document->type_code)) {
                $alertes[] = 'type_incoherent';
            }

            $dateDuTitre = $extracteur->dateAnnonceeParLeTitre($document->titre_officiel);
            $dateSignature = $document->date_signature === null ? null : substr((string) $document->date_signature, 0, 10);

            if ($dateDuTitre !== null && $dateSignature !== null && $dateDuTitre !== $dateSignature) {
                $alertes[] = 'date_incoherente';
            }

            $propositions[] = [
                'id' => $document->id,
                'slug' => $document->slug,
                'titre_officiel' => $document->titre_officiel,
                'type_code' => $document->type_code,
                'date_signature' => $dateSignature,
                'date_titre' => $dateDuTitre,
                'numero_actuel' => $colonneExiste ? $document->numero_acte : null,
                'numero' => $extrait['numero'],
                'brut' => $extrait['brut'],
                'confiance' => $alertes === [] ? $extrait['confiance'] : 'a_verifier',
                'alertes' => $alertes,
                'citation_partagee' => false,
            ];
        }

        $confiance = (string) $this->option('confiance');
        if ($confiance !== '') {
            $propositions = array_values(array_filter(
                $propositions,
                fn (array $proposition) => $proposition['confiance'] === $confiance,
            ));
        }

        $collisions = $this->marquerLesCitationsPartagees($propositions);

        $limite = (int) $this->option('limit');
        if ($limite > 0) {
            $propositions = array_slice($propositions, 0, $limite);
        }

        $chemin = (string) ($this->option('out')
            ?: storage_path('app/numeros-proposes-'.now()->format('Ymd-His').'.json'));

        file_put_contents($chemin, json_encode($propositions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->afficherLeResume($propositions, $rejets, $collisions, $chemin);

        return self::SUCCESS;
    }

    /**
     * Marque les propositions qui donneraient la MÊME citation à deux textes.
     *
     * C'est le garde-fou du schéma d'URL : deux documents qui partagent
     * (type, numéro, date de signature) partageraient l'URL canonique. Cinq
     * paires vues en production le 19/09 sont de vrais doublons à fusionner ;
     * une — le décret 2025-279 et ses statuts annexés — est légitime et devra
     * être nommée. Le relecteur doit les voir dans son fichier, pas les
     * découvrir au moment de la régénération.
     *
     * @param  list<array<string, mixed>>  $propositions
     * @return int Nombre de propositions concernées.
     */
    private function marquerLesCitationsPartagees(array &$propositions): int
    {
        $parCitation = [];

        foreach ($propositions as $rang => $proposition) {
            $citation = implode('|', [
                (string) $proposition['type_code'],
                mb_strtolower((string) $proposition['numero']),
                (string) $proposition['date_signature'],
            ]);

            $parCitation[$citation][] = $rang;
        }

        $concernees = 0;

        foreach ($parCitation as $rangs) {
            if (count($rangs) < 2) {
                continue;
            }

            foreach ($rangs as $rang) {
                $propositions[$rang]['citation_partagee'] = true;
                $concernees++;
            }
        }

        return $concernees;
    }

    /**
     * @param  list<array<string, mixed>>  $propositions
     * @param  array<string, int>  $rejets
     */
    private function afficherLeResume(array $propositions, array $rejets, int $collisions, string $chemin): void
    {
        $this->table(
            ['Titre officiel', 'Numéro', 'Confiance', 'Citation partagée'],
            collect($propositions)->take(15)->map(fn (array $proposition) => [
                mb_strimwidth((string) $proposition['titre_officiel'], 0, 54, '…'),
                (string) $proposition['numero'],
                (string) $proposition['confiance'],
                $proposition['citation_partagee'] ? 'oui' : '',
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

        $typesIncoherents = collect($propositions)->filter(fn (array $p) => in_array('type_incoherent', $p['alertes'], true))->count();
        $datesIncoherentes = collect($propositions)->filter(fn (array $p) => in_array('date_incoherente', $p['alertes'], true))->count();

        if ($typesIncoherents > 0) {
            $this->line(sprintf(
                '%d proposition(s) dont le mot de tête du titre contredit type_code (alerte type_incoherent) : '
                .'phrase de corps promue en titre ? Le numéro serait celui d\'un autre acte.',
                $typesIncoherents,
            ));
        }

        if ($datesIncoherentes > 0) {
            $this->line(sprintf(
                '%d proposition(s) dont la date du titre diffère de date_signature (alerte date_incoherente).',
                $datesIncoherentes,
            ));
        }

        foreach ($this->motifsLisibles($rejets) as $ligne) {
            $this->line($ligne);
        }

        if ($collisions > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d proposition(s) donneraient la MÊME citation qu\'une autre (champ citation_partagee). '
                .'À trancher AVANT toute régénération de slug : doublons à fusionner, ou annexe à nommer.',
                $collisions,
            ));
        }

        $this->newLine();
        $this->warn('AUCUNE ÉCRITURE — ce sont des propositions à relire. Le titre officiel n\'est jamais touché. '
            .'Une fois le fichier relu : '
            .'php artisan mibeko:appliquer-numeros --liste='.$chemin.' --execute');
    }

    /**
     * @param  array<string, int>  $rejets
     * @return list<string>
     */
    private function motifsLisibles(array $rejets): array
    {
        $explications = [
            'numero_absent' => 'aucun « n° … » en tête de titre — référence à retrouver dans le corps ou au JO',
            'titre_non_capitalise' => 'titre commençant par une minuscule — fragment de phrase promu en document, '
                .'à fusionner plutôt qu\'à numéroter',
            'numero_d_un_autre_acte' => 'le seul « n° » du titre est celui d\'un texte CITÉ (« … portant '
                .'application du décret n° … ») — l\'acte n\'a pas de numéro propre dans son titre',
            'forme_non_conforme' => 'numéro lu mais de forme non attestée — à vérifier à la main',
            'numero_illisible' => 'numéro lu mais vide une fois normalisé',
            'titre_vide' => 'titre vide',
        ];

        $lignes = [];

        foreach ($rejets as $motif => $nombre) {
            $lignes[] = sprintf('%d document(s) sans proposition — %s (%s).',
                $nombre, $explications[$motif] ?? $motif, $motif);
        }

        return $lignes;
    }
}
