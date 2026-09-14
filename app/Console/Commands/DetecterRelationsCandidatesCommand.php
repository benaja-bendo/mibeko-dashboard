<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Lance `RelationCandidateDetector` (dashboard#123) sur le corpus publié, via
 * `POST /legal-documents/{id}/detect-relations` — jamais en SQL direct : le
 * détecteur s'exécute côté contrôleur, pas ici.
 *
 * Constat du 14/09/2026 (`mibeko:prod-preflight` + comptage manuel) : la table
 * `document_relations` de production ne contient que 21 lignes, toutes
 * `confirmed`/`human` (import initial de la remédiation #31) — le détecteur
 * heuristique livré par #123 n'a encore jamais tourné sur le corpus publié
 * (1083 documents). La fonctionnalité de relecture des candidats n'a donc
 * aucune donnée réelle à montrer tant que cette commande n'a pas été exécutée.
 *
 * Idempotent par construction : `RelationCandidateDetector::relationDejaTraitee()`
 * ignore un couple (article source, document cible, type) déjà présent en
 * base, quel que soit son statut. Rejouer cette commande sur un document déjà
 * scanné ne duplique donc rien — seul du texte réellement nouveau (nouvelle
 * version d'article) peut produire de nouveaux candidats. Contrairement à
 * `mibeko:creer-relations-documents`, aucun fichier de reprise n'est donc
 * nécessaire : un échec réseau se rejoue en relançant la commande (entière,
 * ou ciblée avec --document-id).
 *
 * Chaque candidat créé reste au statut `candidate` : aucune relation n'est
 * visible du public ni comptée comme un fait établi tant qu'un relecteur ne
 * l'a pas validée (`POST /relations/{id}/valider`, écran dédié du dashboard
 * éditeur). Cette commande ne fait donc que peupler une file de relecture,
 * jamais publier quoi que ce soit.
 *
 *   php artisan mibeko:detecter-relations-candidates                              # simulation, corpus publié entier
 *   php artisan mibeko:detecter-relations-candidates --limit=5 --execute          # lot pilote
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:detecter-relations-candidates --execute                    # corpus publié entier
 */
class DetecterRelationsCandidatesCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:detecter-relations-candidates
        {--connection=pgsql_prod_ro : Connexion utilisée pour LISTER les documents (lecture seule)}
        {--statut=published : Restreindre à un curation_status (vide = tous)}
        {--document-id= : Ne traiter qu\'un seul document, par id}
        {--limit=0 : Limite le nombre de documents traités (0 = tous) — pour un lot pilote}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--rythme=30 : Documents par minute (1 appel API par document)}
        {--rapport= : Fichier JSON de détail par document (défaut : storage/app/detection-relations-<date>.json)}
        {--execute : Appelle réellement l\'API. Sans cette option, simulation seule.}';

    protected $description = 'Détecte les relations MODIFIE/ABROGE candidates sur le corpus, via l\'API (dashboard#123).';

    public function handle(): int
    {
        $connexion = (string) $this->option('connection');

        $requete = DB::connection($connexion)->table('legal_documents')
            ->whereNull('deleted_at')
            ->orderBy('id');

        $statut = (string) $this->option('statut');
        if ($statut !== '') {
            $requete->where('curation_status', $statut);
        }

        $documentId = (string) ($this->option('document-id') ?? '');
        if ($documentId !== '') {
            $requete->where('id', $documentId);
        }

        $documents = $requete->get(['id', 'titre_officiel']);

        $limite = (int) $this->option('limit');
        if ($limite > 0) {
            $documents = $documents->take($limite);
        }

        if ($documents->isEmpty()) {
            $this->info('Aucun document à traiter.');

            return self::SUCCESS;
        }

        $this->table(
            ['Titre officiel'],
            $documents->take(15)->map(fn ($d) => [mb_strimwidth((string) $d->titre_officiel, 0, 70, '…')])->all(),
        );

        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->newLine();
            $this->info($documents->count().' document(s) seraient scannés sur '.rtrim((string) $this->option('base-url'), '/').'.');
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour détecter.');

            return self::SUCCESS;
        }

        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        return $this->executer($documents, rtrim((string) $this->option('base-url'), '/'), $jeton);
    }

    /**
     * @param  Collection<int, object{id: string, titre_officiel: ?string}>  $documents
     */
    private function executer(Collection $documents, string $baseUrl, string $jeton): int
    {
        $rythme = max(0, (int) $this->option('rythme'));
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;
        $total = $documents->count();

        $totalCandidats = 0;
        $totalAmbigus = 0;
        $parType = [];
        $echecs = [];
        $detail = [];
        $rang = 0;

        foreach ($documents as $document) {
            $rang++;
            $debut = microtime(true);
            $label = (string) ($document->titre_officiel ?? $document->id);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            $reponse = $this->poster($jeton, "{$baseUrl}/legal-documents/{$document->id}/detect-relations");

            if ($reponse === null || $reponse->failed()) {
                $echecs[] = [Str::limit($label, 55), $this->motif($reponse)];
                $this->line("<fg=red>✗</> {$avancement} {$label}");
                $this->tenirLaCadence($intervalle, $debut, $rang < $total);

                continue;
            }

            $candidats = collect($reponse->json('data.candidats', []));
            $ambigus = (int) $reponse->json('data.ambigus', 0);

            $totalCandidats += $candidats->count();
            $totalAmbigus += $ambigus;

            foreach ($candidats->pluck('relation_type') as $type) {
                $parType[$type] = ($parType[$type] ?? 0) + 1;
            }

            $detail[] = [
                'document_id' => $document->id,
                'titre_officiel' => $document->titre_officiel,
                'candidats' => $candidats->count(),
                'ambigus' => $ambigus,
            ];

            $suffixe = $candidats->isEmpty() && $ambigus === 0 ? '' : " ({$candidats->count()} candidat(s), {$ambigus} ambigu(s))";
            $this->line("<fg=green>✓</> {$avancement} {$label}{$suffixe}");
            $this->tenirLaCadence($intervalle, $debut, $rang < $total);
        }

        $chemin = (string) ($this->option('rapport')
            ?: storage_path('app/detection-relations-'.now()->format('Ymd-His').'.json'));
        file_put_contents($chemin, json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info("{$total} document(s) scanné(s) — {$totalCandidats} candidat(s) créé(s), {$totalAmbigus} ambiguïté(s) signalée(s) (curation_flags).");

        if ($parType !== []) {
            $this->table(['Type de relation', 'Candidats créés'], collect($parType)
                ->map(fn ($n, $type) => [$type, $n])->values()->all());
        }

        $this->line("Détail par document : {$chemin}");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Document', 'Motif'], $echecs);
            $this->warn(count($echecs).' document(s) en échec — rejouable (idempotent) avec --document-id=<id>, un par un ou via une nouvelle passe complète.');
        }

        $this->newLine();
        $this->warn('Candidats créés au statut `candidate` — invisibles du public tant qu\'un relecteur ne les '
            .'valide pas (écran de relecture du dashboard éditeur, ou POST /relations/{id}/valider).');

        return self::SUCCESS;
    }

    private function poster(string $jeton, string $url): ?Response
    {
        for ($tentative = 1; ; $tentative++) {
            try {
                $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)->post($url);
            } catch (ConnectionException $e) {
                if ($tentative > self::TENTATIVES_MAX) {
                    $this->line('  <fg=red>réseau injoignable</> — '.Str::limit($e->getMessage(), 60));

                    return null;
                }

                $this->attendre($this->backoff($tentative), 'réseau injoignable', $tentative);

                continue;
            }

            if (! $this->estTemporaire($reponse) || $tentative > self::TENTATIVES_MAX) {
                return $reponse;
            }

            $this->attendre(
                $this->delaiDemande($reponse) ?? $this->backoff($tentative),
                (string) $reponse->status(),
                $tentative,
            );
        }
    }

    private function estTemporaire(Response $reponse): bool
    {
        return $reponse->status() === 429 || $reponse->serverError();
    }

    private function delaiDemande(Response $reponse): ?int
    {
        $entete = $reponse->header('Retry-After');

        return is_numeric($entete) ? min((int) $entete, self::ATTENTE_MAX_SECONDES) : null;
    }

    private function backoff(int $tentative): int
    {
        return (int) min(2 ** $tentative, self::ATTENTE_MAX_SECONDES);
    }

    private function attendre(int $secondes, string $motif, int $tentative): void
    {
        $this->line(sprintf(
            '  <fg=yellow>⟳</> %s — nouvelle tentative dans %d s (%d/%d)',
            $motif, $secondes, $tentative, self::TENTATIVES_MAX,
        ));

        Sleep::for($secondes)->seconds();
    }

    private function tenirLaCadence(float $intervalle, float $debut, bool $encoreDesDocuments): void
    {
        if ($intervalle <= 0 || ! $encoreDesDocuments) {
            return;
        }

        $reste = $intervalle - (microtime(true) - $debut);

        if ($reste > 0) {
            Sleep::for((int) round($reste * 1000))->milliseconds();
        }
    }

    private function motif(?Response $reponse): string
    {
        if ($reponse === null) {
            return 'réseau injoignable après '.self::TENTATIVES_MAX.' reprises';
        }

        $corps = $reponse->json();

        return Str::limit((string) (data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
