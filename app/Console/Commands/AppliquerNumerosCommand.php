<?php

namespace App\Console\Commands;

use App\Models\LegalDocument;
use App\Services\Curation\NumeroActeExtractor;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Applique un lot de numéros d'acte RELUS, en passant par l'API Laravel.
 *
 * Consomme le fichier produit par `mibeko:proposer-numeros` une fois qu'un
 * humain l'a relu et corrigé. N'écrit QUE `numero_acte` et sa provenance :
 * ni titre, ni slug, ni statut de curation. Le slug ne bouge pas ici — sa
 * régénération est un chantier distinct (dashboard#156), qui a besoin de ces
 * numéros pour commencer.
 *
 * Trois refus, plutôt qu'un traitement partiel silencieux :
 *
 * 1. Un fichier qui porte un champ `titre`, `slug_propose` ou `nouveau_slug`
 *    est refusé en bloc : il vient d'un autre chantier, et ce canal n'écrit ni
 *    titre ni slug (le champ `slug` des propositions, lui, n'est que le slug
 *    ACTUEL, informatif).
 * 2. Un numéro de forme non attestée est refusé en bloc, en nommant les
 *    coupables : une coquille de relecture qui passerait ici deviendrait une
 *    URL canonique fausse (décision du 19/09/2026), et une collision
 *    silencieuse avec le vrai porteur de cette citation.
 * 3. Sans `--execute`, aucun appel réseau n'est émis.
 *
 * Avant la première écriture, un fichier de RETOUR ARRIÈRE est écrit : il
 * porte, pour chaque document du lot, le numéro qu'il avait avant (le plus
 * souvent nul), au format `--liste`. Il se rejoue tel quel. Ce n'est pas le
 * point de retour principal — le dump frais pris au Temps 3 l'est
 * (`docs/infra/production.md` § 6) — mais il évite d'avoir à restaurer une
 * base entière pour défaire quelques lignes.
 *
 *   export MIBEKO_API_TOKEN='…'
 *   php artisan mibeko:appliquer-numeros --liste=numeros-proposes-*.json            # simulation
 *   php artisan mibeko:appliquer-numeros --liste=numeros-proposes-*.json --execute
 */
class AppliquerNumerosCommand extends Command
{
    private const TENTATIVES_MAX = 4;

    private const ATTENTE_MAX_SECONDES = 60;

    protected $signature = 'mibeko:appliquer-numeros
        {--liste= : Fichier JSON [{id, numero, …}, …] — celui produit par mibeko:proposer-numeros convient tel quel}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--source=titre : Provenance déclarée (titre = extrait du titre officiel, manuel = relu au JO)}
        {--confiance= : Ne retenir que cette confiance (haute, a_verifier) — par défaut, tout le fichier}
        {--rythme=40 : Documents par minute (quota API 60 req/min, 1 appel par document ici)}
        {--retour-arriere= : Fichier de retour arrière (défaut : storage/app/numeros-retour-<date>.json)}
        {--echecs= : Fichier où écrire les documents non traités, au format --liste, pour relancer}
        {--execute : Écrit réellement. Sans cette option, simulation seule.}';

    protected $description = 'Applique un lot relu de numéros d\'acte via l\'API (ne touche ni au titre ni au slug).';

    public function handle(NumeroActeExtractor $extracteur): int
    {
        $chemin = (string) $this->option('liste');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --liste obligatoire : chemin d\'un fichier JSON lisible.');

            return self::FAILURE;
        }

        $source = (string) $this->option('source');

        if (! in_array($source, LegalDocument::NUMERO_ACTE_SOURCES, true)) {
            $this->error('--source doit valoir '.implode(' ou ', LegalDocument::NUMERO_ACTE_SOURCES).'.');

            return self::FAILURE;
        }

        $entrees = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($entrees) || $entrees === []) {
            $this->error('La liste est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $lot = $this->lireLeLot($entrees, $extracteur);

        if ($lot === null) {
            return self::FAILURE;
        }

        if ($lot === []) {
            $this->error('Aucune entrée exploitable (id + numero requis) dans le fichier.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $execute = (bool) $this->option('execute');
        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if (! $execute) {
            return $this->simuler($lot, $baseUrl, $source);
        }

        if ($jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        $this->ecrireLeRetourArriere($lot);

        return $this->executer($lot, $baseUrl, $source, $jeton);
    }

    /**
     * Lit le fichier relu et refuse en bloc ce qui n'a rien à faire là.
     *
     * @param  array<int|string, mixed>  $entrees
     * @return list<array<string, mixed>>|null `null` = fichier refusé.
     */
    private function lireLeLot(array $entrees, NumeroActeExtractor $extracteur): ?array
    {
        $confiance = (string) $this->option('confiance');
        $lot = [];
        $malFormes = [];

        foreach ($entrees as $entree) {
            if (! is_array($entree)) {
                continue;
            }

            // Garde-fou du chantier : ce canal n'écrit ni titre ni slug. Un
            // fichier qui en porterait un a été confondu avec celui d'un autre
            // chantier (`mibeko:corriger-titres-publies`, la régénération de
            // slugs de #156) — on s'arrête plutôt que d'en écrire un.
            foreach (['titre', 'slug_propose', 'nouveau_slug'] as $interdit) {
                if (array_key_exists($interdit, $entree)) {
                    $this->error("Le fichier contient un champ « {$interdit} » : ce lot est destiné à un autre "
                        .'chantier. Cette commande n\'écrit que numero_acte et sa provenance.');

                    return null;
                }
            }

            $id = (string) ($entree['id'] ?? '');

            // Un `numero` explicitement nul est un RETRAIT, pas une entrée
            // inexploitable : c'est la forme qu'a le fichier de retour arrière
            // pour les documents qui n'avaient aucun numéro avant le lot. Sans
            // cette distinction, le retour arrière ne se rejouerait pas.
            $retrait = array_key_exists('numero', $entree) && $entree['numero'] === null;
            $numero = $retrait ? null : $extracteur->normaliser((string) ($entree['numero'] ?? ''));

            if ($id === '' || (! $retrait && $numero === null)) {
                continue;
            }

            if ($confiance !== '' && ($entree['confiance'] ?? null) !== $confiance) {
                continue;
            }

            if (! $retrait && ! $extracteur->estConforme($numero)) {
                $malFormes[] = [Str::limit((string) ($entree['numero'] ?? ''), 30), Str::limit((string) ($entree['titre_officiel'] ?? $id), 60)];

                continue;
            }

            $lot[] = [
                'id' => $id,
                'numero' => $numero,
                'titre_officiel' => $entree['titre_officiel'] ?? null,
                'numero_actuel' => $entree['numero_actuel'] ?? null,
                'confiance' => $entree['confiance'] ?? null,
                'citation_partagee' => (bool) ($entree['citation_partagee'] ?? false),
            ];
        }

        if ($malFormes !== []) {
            $this->error(count($malFormes).' numéro(s) de forme non attestée dans le fichier — rien n\'a été écrit.');
            $this->table(['Numéro lu', 'Document'], array_slice($malFormes, 0, 15));
            $this->line('Forme attendue : sans « n° », sans espace — « 2025-240 », « 3497 », « 80-550/ETR-SGDAAPDP ».');

            return null;
        }

        return $lot;
    }

    /**
     * @param  list<array<string, mixed>>  $lot
     */
    private function simuler(array $lot, string $baseUrl, string $source): int
    {
        $total = count($lot);
        $ecrasements = collect($lot)->filter(
            fn (array $entree) => $entree['numero_actuel'] !== null
                && $entree['numero'] !== null
                && $entree['numero_actuel'] !== $entree['numero'],
        );

        $this->info("{$total} document(s) recevraient un numéro d'acte sur {$baseUrl}.");
        $this->line("Un seul appel par document : PATCH numero_acte (+ source « {$source} »).");
        $this->line('Le titre officiel, le slug et le statut de curation ne changent pas.');
        $this->newLine();

        foreach (array_slice($lot, 0, 30) as $entree) {
            $this->line(sprintf('  · %s', $entree['id']));
            $this->line(sprintf('     titre  : %s', Str::limit((string) ($entree['titre_officiel'] ?? '(inconnu)'), 70)));
            $this->line($entree['numero'] === null
                ? '     numéro : RETRAIT'.($entree['numero_actuel'] === null ? '' : " de « {$entree['numero_actuel']} »")
                : sprintf('     numéro : %s%s',
                    $entree['numero'],
                    $entree['numero_actuel'] === null ? '' : "  (remplace « {$entree['numero_actuel']} »)",
                ));
        }

        if ($total > 30) {
            $this->line(sprintf('  … et %d autre(s).', $total - 30));
        }

        $this->newLine();

        if ($ecrasements->isNotEmpty()) {
            $this->warn($ecrasements->count().' document(s) ont DÉJÀ un numéro différent — il serait remplacé.');
        }

        $partagees = collect($lot)->where('citation_partagee', true)->count();
        if ($partagees > 0) {
            $this->warn($partagees.' document(s) partageraient leur citation (type, numéro, date) avec un autre. '
                .'Le numéro reste juste ; c\'est la régénération de slug qui restera bloquée tant que le doublon '
                .'n\'est pas fusionné ou l\'annexe nommée.');
        }

        $this->warn('SIMULATION — aucun appel réseau émis. Ajouter --execute pour écrire.');

        return self::SUCCESS;
    }

    /**
     * Écrit le lot inverse AVANT la première écriture.
     *
     * Rejouable tel quel : `--liste=<ce fichier> --execute` remet chaque
     * document dans l'état lu au moment des propositions. Les documents qui
     * n'avaient aucun numéro y figurent avec `numero: null` — l'API accepte le
     * retrait, et la contrainte CHECK efface la provenance avec lui.
     *
     * @param  list<array<string, mixed>>  $lot
     */
    private function ecrireLeRetourArriere(array $lot): void
    {
        $chemin = (string) ($this->option('retour-arriere')
            ?: storage_path('app/numeros-retour-'.now()->format('Ymd-His').'.json'));

        $inverse = array_map(fn (array $entree) => [
            'id' => $entree['id'],
            'numero' => $entree['numero_actuel'],
            'titre_officiel' => $entree['titre_officiel'],
        ], $lot);

        file_put_contents($chemin, json_encode($inverse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        $this->line("Retour arrière écrit dans <fg=cyan>{$chemin}</> avant toute écriture.");
        $this->newLine();
    }

    /**
     * @param  list<array<string, mixed>>  $lot
     */
    private function executer(array $lot, string $baseUrl, string $source, string $jeton): int
    {
        $total = count($lot);
        $rythme = max(0, (int) $this->option('rythme'));
        $intervalle = $rythme > 0 ? 60 / $rythme : 0.0;

        $ecrits = 0;
        $echecs = [];
        $arendre = [];
        $rang = 0;

        foreach ($lot as $entree) {
            $rang++;
            $debut = microtime(true);
            $avancement = sprintf('[%d/%d]', $rang, $total);

            // Un retrait n'emporte aucune provenance : la contrainte CHECK
            // refuse une provenance orpheline, et l'API l'efface d'elle-même.
            $charge = $entree['numero'] === null
                ? ['numero_acte' => null]
                : ['numero_acte' => $entree['numero'], 'numero_acte_source' => $source];

            $reponse = $this->patcher($jeton, "{$baseUrl}/legal-documents/{$entree['id']}", $charge);

            if ($reponse === null || $reponse->failed()) {
                $echecs[] = [Str::limit((string) $entree['numero'], 30), $this->motif($reponse)];
                $arendre[] = $entree;
                $this->line("<fg=red>✗</> {$avancement} {$entree['id']} — écriture refusée");
                $this->tenirLaCadence($intervalle, $debut, $rang < $total);

                continue;
            }

            $ecrits++;
            $this->line("<fg=green>✓</> {$avancement} ".($entree['numero'] ?? 'retrait').' — '
                .Str::limit((string) ($entree['titre_officiel'] ?? $entree['id']), 60));
            $this->tenirLaCadence($intervalle, $debut, $rang < $total);
        }

        $this->newLine();
        $this->info("{$ecrits} numéro(s) écrit(s) sur {$total}.");

        if ($echecs !== []) {
            $this->newLine();
            $this->table(['Numéro visé', 'Motif'], $echecs);
            $this->warn(count($echecs).' document(s) non traité(s).');
            $this->ecrireLesEchecs($arendre);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $charge
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

    /**
     * @param  list<array<string, mixed>>  $arendre
     */
    private function ecrireLesEchecs(array $arendre): void
    {
        $chemin = (string) $this->option('echecs');

        if ($chemin === '' || $arendre === []) {
            return;
        }

        file_put_contents($chemin, json_encode($arendre, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        $this->newLine();
        $this->line("Reprise : <fg=cyan>php artisan mibeko:appliquer-numeros --liste={$chemin} --execute</>");
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
