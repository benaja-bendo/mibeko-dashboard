<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Publie une vague de documents en passant par l'API Laravel, jamais en SQL.
 *
 * C'est le canal imposé (docs/infra/production.md § 6) parce que l'API applique
 * ce que le SQL ignore : autorisation par rôle, audit attribué à un utilisateur,
 * contrôle « ≥ 1 article », blocage sur anomalies bloquantes non résolues, et
 * journalisation de `force=true` quand un humain décide de passer outre.
 *
 * Deux appels par document : la machine à états interdit `draft → published`
 * (cf. LegalDocument::CURATION_TRANSITIONS_AVANT), il faut passer par `review`.
 *
 * Le jeton n'est JAMAIS lu depuis un fichier : il s'exporte à la main dans le
 * shell le temps de l'opération, puis se retire.
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:publier-vague --liste=vague-1.json          # simulation
 *   php artisan mibeko:publier-vague --liste=vague-1.json --execute
 *
 * Le fichier `--liste` est un tableau JSON d'identifiants, ou d'objets
 * `{"id": "<uuid>", "titre": "<libellé pour le rapport>"}`.
 *
 * Une vague de masse se heurte au quota `api` (60 requêtes/minute, clé par
 * utilisateur authentifié — cf. AppServiceProvider) : à deux appels par
 * document, la cadence soutenable est de 30 documents/minute. D'où `--rythme`,
 * la reprise automatique sur 429/5xx, et `--echecs` qui écrit les documents non
 * publiés au format `--liste` pour relancer sans retrier à la main.
 */
class PublierVagueCommand extends Command
{
    /** Nombre de reprises après un refus temporaire (429, 5xx, coupure réseau). */
    private const TENTATIVES_MAX = 4;

    /** Plafond du backoff, pour qu'un `Retry-After` farfelu ne fige pas la vague. */
    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:publier-vague
        {--liste= : Fichier JSON des documents à publier}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--date-inconnue : Assume l\'absence de date d\'entrée en vigueur (gate éditorial)}
        {--force : Publie malgré les anomalies bloquantes non résolues (tracé côté API)}
        {--rythme=25 : Documents par minute (le quota API est de 60 requêtes/min, soit 30 documents). 0 désactive la cadence.}
        {--echecs= : Fichier où écrire les documents non publiés, au format --liste, pour relancer}
        {--execute : Publie réellement. Sans cette option, simulation seule.}';

    protected $description = 'Publie une vague de documents via l\'API Laravel (draft → review → published).';

    public function handle(): int
    {
        $chemin = (string) $this->option('liste');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --liste obligatoire : chemin d\'un fichier JSON lisible.');

            return self::FAILURE;
        }

        $entrees = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($entrees) || $entrees === []) {
            $this->error('La liste est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($execute && $jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $charge = array_filter([
            'date_entree_vigueur_inconnue' => $this->option('date-inconnue') ? true : null,
            'force' => $this->option('force') ? true : null,
        ], fn ($v) => $v !== null);

        $rythme = max(0, (int) $this->option('rythme'));
        $total = count($entrees);

        if (! $execute) {
            $this->info("{$total} document(s) seraient publiés sur {$baseUrl}.");
            $this->line('Deux appels par document : curation_status=review, puis curation_status=published.');
            $this->line('Charge utile de publication : '.(json_encode($charge) ?: '{}'));
            $this->line($rythme > 0
                ? "Cadence : {$rythme} document(s)/minute, soit ".$this->duree($total, $rythme).' pour la vague.'
                : 'Cadence : aucune (--rythme=0) — risque de 429 au-delà de 30 documents/minute.');
            $this->newLine();

            foreach ($entrees as $entree) {
                [$id, $titre] = $this->extraire($entree);
                $this->line(sprintf('  · %s  %s', $id, Str::limit($titre, 70)));
            }

            $this->newLine();
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour publier.');

            return self::SUCCESS;
        }

        $publies = 0;
        $echecs = [];
        $arendre = [];
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;
        $rang = 0;

        foreach ($entrees as $entree) {
            [$id, $titre] = $this->extraire($entree);
            $rang++;
            $debut = microtime(true);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            // Étape 1 — passage en revue (transition obligatoire depuis draft).
            $revue = $this->patcher($jeton, "{$baseUrl}/legal-documents/{$id}", ['curation_status' => 'review']);

            if ($revue === null || ($revue->failed() && ! $this->dejaAuMoinsEnRevue($revue))) {
                $echecs[] = [Str::limit($titre, 44), 'revue', $this->motif($revue)];
                $arendre[] = ['id' => $id, 'titre' => $titre];
                $this->line("<fg=red>✗</> {$avancement} {$titre} — mise en revue refusée");
                $this->tenirLaCadence($intervalle, $debut, $rang < $total);

                continue;
            }

            // Étape 2 — publication.
            $publication = $this->patcher($jeton, "{$baseUrl}/legal-documents/{$id}", [
                'curation_status' => 'published',
            ] + $charge);

            if ($publication === null || $publication->failed()) {
                $echecs[] = [Str::limit($titre, 44), 'publication', $this->motif($publication)];
                $arendre[] = ['id' => $id, 'titre' => $titre];
                $this->line("<fg=red>✗</> {$avancement} {$titre} — publication refusée");
                $this->tenirLaCadence($intervalle, $debut, $rang < $total);

                continue;
            }

            $publies++;
            $this->line("<fg=green>✓</> {$avancement} {$titre}");
            $this->tenirLaCadence($intervalle, $debut, $rang < $total);
        }

        $this->newLine();
        $this->info("{$publies} document(s) publié(s) sur {$total}.");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Document', 'Étape', 'Motif'], $echecs);
            $this->warn(count($echecs).' document(s) non publié(s) — le corpus n\'est pas dans un état partiel : '
                .'chaque document est traité indépendamment, ceux en échec sont restés à leur statut d\'origine.');

            $this->ecrireLesEchecs($arendre);
        }

        return self::SUCCESS;
    }

    /**
     * Émet le PATCH en reprenant sur les refus temporaires — 429 (quota),
     * 5xx (incident serveur) et coupures réseau. Les refus définitifs (422 d'un
     * garde-fou éditorial, 403) sont rendus tels quels : ils ne se réessaient
     * pas, ils se corrigent.
     *
     * @param  array<string, mixed>  $charge
     * @return Response|null null quand le réseau n'a jamais répondu
     */
    private function patcher(string $jeton, string $url, array $charge): ?Response
    {
        for ($tentative = 1; ; $tentative++) {
            try {
                $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)->patch($url, $charge);
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

    /** Le serveur sait mieux que nous quand revenir : `Retry-After` prime sur le backoff. */
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

    /**
     * Tient la cadence en déduisant le temps déjà passé sur le document : sans
     * cette compensation, la vague avancerait bien plus lentement qu'annoncé.
     */
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

    /**
     * Écrit les documents non publiés au format `--liste`, pour que la reprise
     * soit un rappel de la même commande sur ce fichier — jamais un tri manuel
     * de la sortie console.
     *
     * @param  list<array{id: string, titre: string}>  $arendre
     */
    private function ecrireLesEchecs(array $arendre): void
    {
        $chemin = (string) $this->option('echecs');

        if ($chemin === '' || $arendre === []) {
            return;
        }

        file_put_contents($chemin, json_encode($arendre, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        $this->newLine();
        $this->line("Reprise : <fg=cyan>php artisan mibeko:publier-vague --liste={$chemin} --execute</> "
            .'(après avoir traité le motif de refus).');
    }

    private function duree(int $documents, int $rythme): string
    {
        $minutes = (int) ceil($documents / max(1, $rythme));

        return $minutes < 60 ? "~{$minutes} min" : sprintf('~%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function extraire(mixed $entree): array
    {
        if (is_string($entree)) {
            return [$entree, $entree];
        }

        return [(string) ($entree['id'] ?? ''), (string) ($entree['titre'] ?? $entree['id'] ?? '')];
    }

    /**
     * Un document déjà en `review` (ou plus loin) fait échouer l'étape 1 sur la
     * garde de transition : ce n'est pas une erreur, l'étape 2 peut suivre.
     */
    private function dejaAuMoinsEnRevue(Response $reponse): bool
    {
        return $reponse->status() === 422
            && str_contains(strtolower($reponse->body()), 'transition de statut');
    }

    private function motif(?Response $reponse): string
    {
        if ($reponse === null) {
            return 'réseau injoignable après '.self::TENTATIVES_MAX.' reprises';
        }

        $corps = $reponse->json();
        $erreurs = data_get($corps, 'errors', []);
        $premiere = is_array($erreurs) ? (data_get($erreurs, '*.0')[0] ?? null) : null;

        return Str::limit((string) ($premiere ?: data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
