<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
 */
class PublierVagueCommand extends Command
{
    protected $signature = 'mibeko:publier-vague
        {--liste= : Fichier JSON des documents à publier}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--date-inconnue : Assume l\'absence de date d\'entrée en vigueur (gate éditorial)}
        {--force : Publie malgré les anomalies bloquantes non résolues (tracé côté API)}
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

        if (! $execute) {
            $this->info(count($entrees)." document(s) seraient publiés sur {$baseUrl}.");
            $this->line('Deux appels par document : curation_status=review, puis curation_status=published.');
            $this->line('Charge utile de publication : '.(json_encode($charge) ?: '{}'));
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

        foreach ($entrees as $entree) {
            [$id, $titre] = $this->extraire($entree);

            $requete = fn () => Http::withToken($jeton)
                ->acceptJson()
                ->timeout(30);

            // Étape 1 — passage en revue (transition obligatoire depuis draft).
            $revue = $requete()->patch("{$baseUrl}/legal-documents/{$id}", ['curation_status' => 'review']);

            if ($revue->failed() && ! $this->dejaAuMoinsEnRevue($revue)) {
                $echecs[] = [Str::limit($titre, 44), 'revue', $this->motif($revue)];
                $this->line("<fg=red>✗</> {$titre} — mise en revue refusée");

                continue;
            }

            // Étape 2 — publication.
            $publication = $requete()->patch("{$baseUrl}/legal-documents/{$id}", [
                'curation_status' => 'published',
            ] + $charge);

            if ($publication->failed()) {
                $echecs[] = [Str::limit($titre, 44), 'publication', $this->motif($publication)];
                $this->line("<fg=red>✗</> {$titre} — publication refusée");

                continue;
            }

            $publies++;
            $this->line("<fg=green>✓</> {$titre}");
        }

        $this->newLine();
        $this->info("{$publies} document(s) publié(s).");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Document', 'Étape', 'Motif'], $echecs);
            $this->warn(count($echecs).' document(s) non publié(s) — le corpus n\'est pas dans un état partiel : '
                .'chaque document est traité indépendamment, ceux en échec sont restés à leur statut d\'origine.');
        }

        return self::SUCCESS;
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

    private function motif(Response $reponse): string
    {
        $corps = $reponse->json();
        $erreurs = data_get($corps, 'errors', []);
        $premiere = is_array($erreurs) ? (data_get($erreurs, '*.0')[0] ?? null) : null;

        return Str::limit((string) ($premiere ?: data_get($corps, 'message') ?: $reponse->status()), 60);
    }
}
