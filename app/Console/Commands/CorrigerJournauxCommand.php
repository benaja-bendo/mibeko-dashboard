<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Corrige des fiches de Journal officiel publiées, et le rattachement d'un
 * acte à son numéro, par l'API — jamais en SQL (dashboard#218).
 *
 * Constat du 30/09/2026 : 34 numéros publiés n'affichaient aucun texte (fiches
 * créées par le push du corpus pour des actes jamais poussés, texte unique
 * publié en STOCK, JO non découpé supprimé comme fantôme, acte publié mais
 * détaché de son numéro), et un numéro portait un nom de fichier pour titre.
 * La correction de chaque fiche est une décision relue par un humain : la
 * commande ne devine rien, elle applique un lot.
 *
 * Un lot est un tableau JSON d'entrées :
 *
 *   { "cible": "journal" | "document", "id": "<uuid>", "libelle": "…",
 *     "avant": {…}, "apres": {…}, "motif": "…" }
 *
 * `avant` est l'état mesuré au diagnostic : il est relu en base avant toute
 * écriture. Une entrée déjà à `apres` est sautée (la commande est rejouable) ;
 * une entrée qui n'est ni à `avant` ni à `apres` bloque TOUT le lot — la base
 * n'est plus celle que le lot prétend corriger. Seuls les champs de
 * CHAMPS_AUTORISES sont acceptés : publier un document ne passe jamais par ici.
 *
 * Le fichier de retour arrière est écrit AVANT la première écriture, au format
 * d'un lot (avant et apres inversés) : il se rejoue avec cette même commande.
 * Après chaque PATCH, l'état est relu sur la connexion de lecture et doit être
 * exactement `apres`.
 *
 *   php artisan mibeko:corriger-journaux --lot=lot.json                  # simulation, lecture pgsql_prod_ro
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:corriger-journaux --lot=lot.json --limit=5 --execute
 *   php artisan mibeko:corriger-journaux --lot=lot.json --execute
 */
class CorrigerJournauxCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    /**
     * Table lue, endpoint écrit et champs modifiables, par cible.
     */
    private const CIBLES = [
        'journal' => ['table' => 'official_journals', 'endpoint' => '/official-journals/'],
        'document' => ['table' => 'legal_documents', 'endpoint' => '/legal-documents/'],
    ];

    private const CHAMPS_AUTORISES = [
        'journal' => ['title', 'number', 'is_published'],
        'document' => ['official_journal_id'],
    ];

    protected $signature = 'mibeko:corriger-journaux
        {--lot= : Fichier JSON [{cible, id, libelle, avant, apres, motif}, …]}
        {--connection=pgsql_prod_ro : Connexion LUE pour vérifier l\'état avant et après écriture}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API écrite}
        {--rythme=40 : Écritures par minute (quota API 60 req/min, 1 appel par entrée)}
        {--limit= : N premières entrées à écrire (lot pilote)}
        {--revert-file= : Où écrire le lot de retour arrière (défaut : storage/app/)}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Corrige des fiches de Journal officiel (titre, numéro, visibilité) et le rattachement d\'actes, via l\'API.';

    public function handle(): int
    {
        $chemin = (string) $this->option('lot');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --lot obligatoire : chemin d\'un fichier JSON lisible.');

            return self::FAILURE;
        }

        $entrees = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($entrees) || $entrees === [] || ! array_is_list($entrees)) {
            $this->error('Le lot est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($execute && $jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        $plans = [];
        $refus = [];

        foreach ($entrees as $rang => $entree) {
            $plan = $this->preparer(is_array($entree) ? $entree : [], $rang);

            if (isset($plan['refus'])) {
                $refus[] = [Str::limit($plan['libelle'], 50), $plan['refus']];

                continue;
            }

            $plans[] = $plan;
        }

        $this->afficherLePlan($plans);

        // Un lot n'est jamais exécuté à moitié : une seule entrée hors de
        // l'état attendu signifie que la base a bougé depuis le diagnostic, et
        // les autres entrées reposent sur la même lecture, désormais suspecte.
        if ($refus !== []) {
            $this->newLine();
            $this->table(['Entrée refusée', 'Motif'], $refus);
            $this->error(count($refus).' entrée(s) refusée(s) — lot non exécuté. Refaire le diagnostic, corriger le lot, relancer.');

            return self::FAILURE;
        }

        $aAppliquer = array_values(array_filter($plans, fn (array $plan) => $plan['etat'] === 'à appliquer'));
        $dejaAppliques = count($plans) - count($aAppliquer);
        $limite = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $lot = $limite !== null ? array_slice($aAppliquer, 0, $limite) : $aAppliquer;

        $this->newLine();
        $this->info(count($lot).' ligne(s) seraient modifiées sur '.$this->baseUrl()
            ." ({$dejaAppliques} déjà à l'état voulu, sautée(s)).");

        if (! $execute) {
            $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour écrire.');

            return self::SUCCESS;
        }

        if ($lot === []) {
            $this->info('Rien à écrire.');

            return self::SUCCESS;
        }

        $fichierRetour = (string) ($this->option('revert-file')
            ?: storage_path('app/retour-journaux-'.now()->format('Ymd-His').'.json'));

        // Écrit AVANT la première modification : si l'exécution s'interrompt,
        // le fichier couvre déjà tout ce qui a pu être écrit.
        file_put_contents($fichierRetour, json_encode(
            array_map(fn (array $plan) => [
                'cible' => $plan['cible'],
                'id' => $plan['id'],
                'libelle' => $plan['libelle'],
                'avant' => $plan['apres'],
                'apres' => $plan['avant'],
                'motif' => 'Retour arrière de : '.$plan['motif'],
            ], $lot),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        $this->info("Retour arrière écrit : {$fichierRetour}");

        return $this->executer($lot, $jeton);
    }

    /**
     * Valide une entrée du lot et la confronte à l'état relu en base.
     *
     * @param  array<string, mixed>  $entree
     * @return array<string, mixed>
     */
    private function preparer(array $entree, int $rang): array
    {
        $cible = (string) ($entree['cible'] ?? '');
        $id = (string) ($entree['id'] ?? '');
        $libelle = (string) ($entree['libelle'] ?? "entrée #{$rang}");
        $avant = $entree['avant'] ?? null;
        $apres = $entree['apres'] ?? null;

        if (! isset(self::CIBLES[$cible])) {
            return ['libelle' => $libelle, 'refus' => 'cible inconnue (attendu : journal ou document)'];
        }

        if (! Str::isUuid($id)) {
            return ['libelle' => $libelle, 'refus' => 'id absent ou qui n\'est pas un UUID'];
        }

        if (! is_array($avant) || ! is_array($apres) || $apres === [] || array_is_list($apres)) {
            return ['libelle' => $libelle, 'refus' => '`avant` et `apres` doivent être des objets non vides'];
        }

        $champs = array_keys($apres);
        sort($champs);
        $champsAvant = array_keys($avant);
        sort($champsAvant);

        if ($champs !== $champsAvant) {
            return ['libelle' => $libelle, 'refus' => '`avant` et `apres` ne portent pas les mêmes champs'];
        }

        $interdits = array_diff($champs, self::CHAMPS_AUTORISES[$cible]);

        if ($interdits !== []) {
            return ['libelle' => $libelle, 'refus' => 'champ(s) hors liste blanche : '.implode(', ', $interdits)];
        }

        if ($this->normaliser($avant) === $this->normaliser($apres)) {
            return ['libelle' => $libelle, 'refus' => '`avant` et `apres` sont identiques'];
        }

        $actuel = $this->lireEtat($cible, $id, $champs);

        if ($actuel === null) {
            return ['libelle' => $libelle, 'refus' => 'introuvable ou supprimé en base'];
        }

        $etat = match ($this->normaliser($actuel)) {
            $this->normaliser($avant) => 'à appliquer',
            $this->normaliser($apres) => 'déjà appliqué',
            default => null,
        };

        if ($etat === null) {
            return [
                'libelle' => $libelle,
                'refus' => 'dérive : en base '.$this->decrire($actuel).', attendu '.$this->decrire($avant),
            ];
        }

        return [
            'cible' => $cible,
            'id' => $id,
            'libelle' => $libelle,
            'avant' => $avant,
            'apres' => $apres,
            'motif' => (string) ($entree['motif'] ?? ''),
            'etat' => $etat,
        ];
    }

    /**
     * @param  list<string>  $champs
     * @return array<string, mixed>|null
     */
    private function lireEtat(string $cible, string $id, array $champs): ?array
    {
        $ligne = $this->connexion()
            ->table(self::CIBLES[$cible]['table'])
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first($champs);

        return $ligne === null ? null : (array) $ligne;
    }

    /**
     * Rend comparables une valeur lue en base et une valeur du lot : ordre des
     * clés, booléens Postgres, identifiants UUID.
     *
     * @param  array<string, mixed>  $valeurs
     * @return array<string, bool|string|null>
     */
    private function normaliser(array $valeurs): array
    {
        ksort($valeurs);

        return array_map(fn (mixed $valeur) => match (true) {
            $valeur === null => null,
            is_bool($valeur) => $valeur,
            default => (string) $valeur,
        }, $valeurs);
    }

    /**
     * @param  array<string, mixed>  $valeurs
     */
    private function decrire(array $valeurs): string
    {
        return (string) json_encode($this->normaliser($valeurs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function afficherLePlan(array $plans): void
    {
        if ($plans === []) {
            return;
        }

        $this->table(
            ['Cible', 'Avant', 'Après', 'État'],
            array_map(fn (array $plan) => [
                Str::limit($plan['libelle'], 40),
                Str::limit($this->decrire($plan['avant']), 50),
                Str::limit($this->decrire($plan['apres']), 50),
                $plan['etat'],
            ], $plans),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $lot
     */
    private function executer(array $lot, string $jeton): int
    {
        $rythme = max(0, (int) $this->option('rythme'));
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;
        $total = count($lot);
        $ecrites = 0;
        $echecs = [];
        $rang = 0;

        foreach ($lot as $plan) {
            $rang++;
            $debut = microtime(true);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            $reponse = $this->patcher($jeton, self::CIBLES[$plan['cible']]['endpoint'].$plan['id'], $plan['apres']);

            if ($reponse !== true) {
                $echecs[] = [Str::limit($plan['libelle'], 50), $reponse === null ? 'API injoignable' : 'refusé par l\'API'];
                $this->line("{$avancement} ✗ {$plan['libelle']}");
                $this->respirer($intervalle, $debut, $rang, $total);

                continue;
            }

            // L'API a répondu 2xx : on le constate en base, on ne le suppose pas.
            $relu = $this->lireEtat($plan['cible'], $plan['id'], array_keys($plan['apres']));

            if ($relu === null || $this->normaliser($relu) !== $this->normaliser($plan['apres'])) {
                $echecs[] = [
                    Str::limit($plan['libelle'], 50),
                    'écart après écriture : '.($relu === null ? 'ligne introuvable' : $this->decrire($relu)),
                ];
                $this->line("{$avancement} ⚠ {$plan['libelle']} — écart après écriture");
                $this->respirer($intervalle, $debut, $rang, $total);

                continue;
            }

            $ecrites++;
            $this->line("{$avancement} ✓ {$plan['libelle']}");
            $this->respirer($intervalle, $debut, $rang, $total);
        }

        $this->newLine();
        $this->info("{$ecrites}/{$total} ligne(s) modifiée(s) et vérifiée(s).");

        if ($echecs !== []) {
            $this->table(['Échec', 'Motif'], $echecs);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function patcher(string $jeton, string $chemin, array $charge): ?bool
    {
        $url = $this->baseUrl().$chemin;

        for ($tentative = 1; $tentative <= self::TENTATIVES_MAX; $tentative++) {
            try {
                $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)->patch($url, $charge);
            } catch (ConnectionException $e) {
                $this->warn('  connexion impossible : '.$e->getMessage());

                return null;
            }

            if ($reponse->status() !== 429) {
                if ($reponse->successful()) {
                    return true;
                }

                $this->warn('  '.$reponse->status().' — '.Str::limit($reponse->body(), 200));

                return false;
            }

            $attente = min(self::ATTENTE_MAX_SECONDES, (int) ($reponse->header('Retry-After') ?: 2 ** $tentative));
            $this->warn("  429 reçu, reprise dans {$attente}s (tentative {$tentative}/".self::TENTATIVES_MAX.')');
            Sleep::sleep($attente);
        }

        return false;
    }

    private function connexion(): ConnectionInterface
    {
        $nom = (string) $this->option('connection');

        return DB::connection($nom === '' ? null : $nom);
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->option('base-url'), '/');
    }

    private function respirer(float $intervalle, float $debut, int $rang, int $total): void
    {
        if ($intervalle <= 0 || $rang >= $total) {
            return;
        }

        $reste = $intervalle - (microtime(true) - $debut);

        if ($reste > 0) {
            Sleep::usleep((int) ($reste * 1_000_000));
        }
    }
}
