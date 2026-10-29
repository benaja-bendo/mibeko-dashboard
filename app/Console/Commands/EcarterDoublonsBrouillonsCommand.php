<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AppelleLApiAvecReprise;
use App\Console\Commands\Concerns\AuditeSurLaConnexionCible;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Services\Curation\DoublonsBrouillons;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Écarte les brouillons jumeaux d'un même acte (même titre officiel, même
 * date) en ne gardant que le meilleur de chaque groupe, puis referme le
 * signalement `doublon_titre_date` des exemplaires conservés.
 *
 * Pourquoi : un même Journal officiel publié sous plusieurs fichiers PDF est
 * structuré une fois par fichier (mibeko-python#52). Le 17/09/2026, six JO ont
 * ainsi déposé 543 brouillons pour 216 actes. La définition d'un jumeau est
 * dans `DoublonsBrouillons`.
 *
 * Canal API (docs/infra/production.md § 6, Classe 2) : `DELETE
 * /legal-documents/{id}` (suppression douce, audit attribué, articles
 * supprimés avec le document) puis `PATCH /curation-flags/{id}`. La connexion
 * `--connection` ne sert qu'à LIRE : sélection, puis relecture juste avant
 * chaque appel, de sorte qu'un document devenu publié entre-temps n'est
 * jamais supprimé.
 *
 * Rejouable : la sélection est recalculée à chaque exécution, un document déjà
 * écarté n'en fait plus partie. Retour arrière : `--annuler` avec le fichier
 * `--liste` d'une exécution, ou le dump frais qui précède toute écriture.
 *
 *   php artisan mibeko:ecarter-doublons-brouillons --liste=ecartes.json --rapport=a-instruire.json
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:ecarter-doublons-brouillons --liste=ecartes.json --attendu=N --limit=5 --execute
 *   php artisan mibeko:ecarter-doublons-brouillons --liste=ecartes.json --attendu=N-5 --execute
 */
class EcarterDoublonsBrouillonsCommand extends Command
{
    use AppelleLApiAvecReprise;
    use AuditeSurLaConnexionCible;

    protected $signature = 'mibeko:ecarter-doublons-brouillons
        {--connection=pgsql_prod_ro : Connexion de LECTURE (sélection et relecture avant chaque appel)}
        {--liste= : Fichier où écrire les documents écartés et les signalements résolus (base du retour arrière)}
        {--rapport= : Fichier où écrire les groupes laissés à instruire}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--rythme=40 : Appels par minute (quota API de 60/min)}
        {--limit=0 : Plafonne le nombre de documents écartés (lot pilote)}
        {--attendu= : Nombre exact de documents à écarter ; refuse d\'exécuter si la sélection en compte un autre}
        {--annuler= : Fichier --liste d\'une exécution à défaire (utiliser une connexion en écriture)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Écarte les brouillons jumeaux d\'un même acte via l\'API, puis referme leur signalement de doublon.';

    public function handle(DoublonsBrouillons $doublons): int
    {
        $connexion = (string) $this->option('connection');
        $execute = (bool) $this->option('execute');

        if ((string) $this->option('annuler') !== '') {
            return $this->annuler($connexion, $execute);
        }

        $liste = (string) $this->option('liste');

        if ($execute && $liste !== '' && file_exists($liste)) {
            $this->error("{$liste} existe déjà : il porte peut-être le retour arrière d'une exécution précédente. Choisir un autre nom.");

            return self::FAILURE;
        }

        $analyse = $doublons->analyser($connexion);
        $ecartes = $analyse['ecartes'];
        $attendu = $this->option('attendu');

        $this->afficherLeBilan($analyse);
        $this->ecrireJson((string) $this->option('rapport'), $analyse['a_instruire'], 'Groupes à instruire');

        if ($attendu !== null && (int) $attendu !== count($ecartes)) {
            $this->error(sprintf(
                'Écart sur l\'effectif : %d document(s) attendus, %d sélectionnés. Rien n\'est écrit : un écart inexpliqué se traite comme un incident.',
                (int) $attendu, count($ecartes),
            ));

            return self::FAILURE;
        }

        if (! $execute) {
            $this->ecrireLaListe($ecartes, []);
            $this->newLine();
            $this->warn('SIMULATION : aucun appel réseau émis. Ajouter --execute pour écarter.');

            return self::SUCCESS;
        }

        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $intervalle = $this->intervalle();
        $limite = max(0, (int) $this->option('limit'));
        $aEcarter = $limite > 0 ? array_slice($ecartes, 0, $limite) : $ecartes;

        [$ecartesEffectifs, $echecs] = $this->ecarter($connexion, $jeton, $baseUrl, $aEcarter, $intervalle);

        // État relu APRÈS les suppressions : seuls les documents qui n'ont
        // réellement plus de jumeau voient leur signalement refermé.
        $resolus = $this->refermer(
            $jeton, $baseUrl,
            array_values(array_filter(
                $doublons->analyser($connexion)['signalements_a_resoudre'],
                fn (array $s) => $s['motif'] === 'plus de jumeau vivant',
            )),
            $intervalle,
        );

        $this->ecrireLaListe($ecartesEffectifs, $resolus);

        $this->newLine();
        $this->info(sprintf(
            '%d document(s) écarté(s) sur %d prévu(s), %d signalement(s) de doublon refermé(s).',
            count($ecartesEffectifs), count($aEcarter), count($resolus),
        ));

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Document', 'Motif'], $echecs);
            $this->warn(count($echecs).' document(s) non écartés, restés intacts. Rejouer la même commande : la sélection se recalcule.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $aEcarter
     * @return array{0: list<array<string, mixed>>, 1: list<array{0: string, 1: string}>} documents écartés, échecs
     */
    private function ecarter(string $connexion, string $jeton, string $baseUrl, array $aEcarter, float $intervalle): array
    {
        $ecartes = [];
        $echecs = [];
        $total = count($aEcarter);

        foreach ($aEcarter as $rang => $document) {
            $debut = microtime(true);
            $avancement = sprintf('[%d/%d]', $rang + 1, $total);
            $titre = Str::limit((string) $document['titre'], 60);

            $refus = $this->refusAvantAppel($connexion, $document);

            if ($refus !== null) {
                $echecs[] = [Str::limit($titre, 44), $refus];
                $this->line("<fg=yellow>↷</> {$avancement} {$titre} : ignoré ({$refus})");

                continue;
            }

            $reponse = $this->appelerApi($jeton, 'DELETE', "{$baseUrl}/legal-documents/{$document['id']}");

            if ($reponse === null || $reponse->failed()) {
                $echecs[] = [Str::limit($titre, 44), $this->motifDeRefus($reponse)];
                $this->line("<fg=red>✗</> {$avancement} {$titre} : suppression refusée");
            } else {
                $ecartes[] = $document;
                $this->line("<fg=green>✓</> {$avancement} {$titre}");
                // Au fil de l'eau : une exécution interrompue laisse son retour arrière.
                $this->ecrireLaListe($ecartes, [], silencieux: true);
            }

            if ($rang < $total - 1) {
                $this->tenirLaCadence($intervalle, $debut);
            }
        }

        return [$ecartes, $echecs];
    }

    /**
     * Relecture juste avant l'appel : la sélection date de quelques minutes,
     * et l'API n'oppose aucun garde-fou de statut à une suppression douce.
     *
     * @param  array<string, mixed>  $document
     */
    private function refusAvantAppel(string $connexion, array $document): ?string
    {
        $vivants = fn (string $id) => DB::connection($connexion)->table('legal_documents')
            ->where('id', $id)->whereNull('deleted_at');

        if (! $vivants($document['id'])->where('curation_status', 'draft')->exists()) {
            return 'plus un brouillon vivant';
        }

        if (! $vivants($document['conserve_id'])->exists()) {
            return 'exemplaire conservé disparu';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $signalements
     * @return list<string> identifiants des signalements refermés
     */
    private function refermer(string $jeton, string $baseUrl, array $signalements, float $intervalle): array
    {
        $resolus = [];

        foreach ($signalements as $rang => $signalement) {
            $debut = microtime(true);
            $reponse = $this->appelerApi($jeton, 'PATCH', "{$baseUrl}/curation-flags/{$signalement['signalement_id']}", ['resolved' => true]);

            if ($reponse === null || $reponse->failed()) {
                $this->line("<fg=red>✗</> signalement {$signalement['signalement_id']} : ".$this->motifDeRefus($reponse));
            } else {
                $resolus[] = $signalement['signalement_id'];
            }

            if ($rang < count($signalements) - 1) {
                $this->tenirLaCadence($intervalle, $debut);
            }
        }

        return $resolus;
    }

    /**
     * Défait une exécution : restaure les documents écartés (leurs articles
     * reviennent avec eux, `LegalDocument::booted`) et rouvre les signalements
     * refermés. Écrit en base, pas par l'API (aucune route de restauration) :
     * connexion en écriture obligatoire, transaction, audit sur la connexion
     * visée. Un écart entre la liste et l'état réel arrête tout.
     */
    private function annuler(string $connexion, bool $execute): int
    {
        if ($connexion === 'pgsql_prod_ro') {
            $this->error('--annuler écrit : passer --connection=pgsql_prod_rw (ou pgsql en développement).');

            return self::FAILURE;
        }

        $chemin = (string) $this->option('annuler');
        $liste = is_readable($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;

        if (! is_array($liste) || ! isset($liste['ecartes'])) {
            $this->error('--annuler attend le fichier --liste d\'une exécution (clés « ecartes » et « signalements_resolus »).');

            return self::FAILURE;
        }

        $documents = array_column($liste['ecartes'], 'id');
        $signalements = $liste['signalements_resolus'] ?? [];

        $supprimes = LegalDocument::on($connexion)->onlyTrashed()->whereIn('id', $documents)->count();

        $this->info(sprintf('%d document(s) seraient restaurés, %d signalement(s) rouverts.', $supprimes, count($signalements)));

        if ($supprimes !== count($documents)) {
            $this->error(sprintf(
                'Écart : %d document(s) dans la liste, %d actuellement supprimés. Rien n\'est écrit.',
                count($documents), $supprimes,
            ));

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('SIMULATION : rien n\'est écrit. Ajouter --execute pour restaurer.');

            return self::SUCCESS;
        }

        $this->avecAuditSurConnexion($connexion, function () use ($connexion, $documents, $signalements): void {
            DB::connection($connexion)->transaction(function () use ($connexion, $documents, $signalements): void {
                LegalDocument::on($connexion)->onlyTrashed()->whereIn('id', $documents)->get()
                    ->each(fn (LegalDocument $document) => $document->restore());

                CurationFlag::on($connexion)->whereIn('id', $signalements)
                    ->update(['resolved' => false, 'resolved_at' => null, 'resolved_by' => null]);
            });
        });

        $this->info('Restauré.');

        return self::SUCCESS;
    }

    /**
     * @param  array{groupes: int, ecartes: list<array<string, mixed>>, a_instruire: list<array<string, mixed>>, signalements_a_resoudre: list<array<string, mixed>>}  $analyse
     */
    private function afficherLeBilan(array $analyse): void
    {
        $this->newLine();
        $this->table(['Mesure', 'Valeur'], [
            ['Groupes de jumeaux', $analyse['groupes']],
            ['Documents à écarter', count($analyse['ecartes'])],
            ['Groupes laissés à instruire', count($analyse['a_instruire'])],
            ['Signalements de doublon à refermer', count($analyse['signalements_a_resoudre'])],
        ]);

        $this->line('Exemples (conservé en premier) :');

        foreach (array_slice($analyse['ecartes'], 0, 5) as $document) {
            $this->line(sprintf(
                '  · %s (%d article(s), %d bloquant(s)) garde la place de %s',
                Str::limit($document['titre'], 52), $document['articles'], $document['conserve_bloquants'], $document['id'],
            ));
        }

        foreach ($analyse['a_instruire'] as $groupe) {
            $this->line(sprintf('  ! à instruire : %s (%s)', Str::limit($groupe['titre'], 52), implode(', ', $groupe['motifs'])));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $ecartes
     * @param  list<string>  $signalementsResolus
     */
    private function ecrireLaListe(array $ecartes, array $signalementsResolus, bool $silencieux = false): void
    {
        $this->ecrireJson((string) $this->option('liste'), [
            'ecartes' => $ecartes,
            'signalements_resolus' => $signalementsResolus,
        ], 'Liste des documents écartés', $silencieux);
    }

    /**
     * @param  array<mixed>  $donnees
     */
    private function ecrireJson(string $chemin, array $donnees, string $libelle, bool $silencieux = false): void
    {
        if ($chemin === '') {
            return;
        }

        file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        if (! $silencieux) {
            $this->line("{$libelle} : {$chemin}");
        }
    }

    private function intervalle(): float
    {
        $rythme = max(0, (int) $this->option('rythme'));

        return $rythme > 0 ? 60 / $rythme : 0.0;
    }
}
