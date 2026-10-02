<?php

namespace App\Console\Commands;

use App\Traits\SearchesArticles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rejoue le jeu de questions de référence de la recherche (mibeko-dashboard#233).
 *
 * Chaque cas de `database/reference/recherche-reference.json` donne une question
 * telle qu'un usager la tape et l'article qui y répond. La commande passe par le
 * moteur réel ({@see SearchesArticles}), sans le contrôleur : rien n'est écrit,
 * pas même le journal des recherches. Elle sert de mesure avant/après à toute
 * retouche du classement, sur la copie locale de la production ou sur la
 * production en lecture seule.
 *
 * Deux surfaces : `publique` (recherche du site et de l'application, filet
 * sémantique pour les questions en phrase, API-020) et `assistant` (outil de
 * l'assistant, filet sémantique toujours). Le filet a besoin d'un fournisseur
 * d'embeddings joignable ; sans lui, la recherche se dégrade vers le lexical, et
 * la colonne « Sém. » le montre.
 *
 * Les embeddings des questions sont calculés avant la mesure, avec un délai
 * large, et leur latence est donnée à part : le rang ne doit pas dépendre d'un
 * moment de lenteur du réseau, et la durée par cas est celle du moteur, embedding
 * en cache.
 */
class MesurerRechercheCommand extends Command
{
    use SearchesArticles;

    protected $signature = 'mibeko:mesurer-recherche
        {--connection=pgsql_prod_ro : Connexion à interroger (pgsql_prod_ro par défaut ; passer la connexion locale pour la copie de la production)}
        {--fichier=database/reference/recherche-reference.json : Jeu de questions de référence}
        {--surface=publique : publique (site et application) ou assistant (outil de l\'assistant)}
        {--profondeur=50 : Nombre de résultats examinés par question}
        {--detail : Affiche la tête des résultats des cas hors tolérance}';

    protected $description = 'Rejoue le jeu de questions de référence et donne le rang de l\'article attendu (lecture seule).';

    public function handle(): int
    {
        $nom = (string) $this->option('connection');
        $surface = (string) $this->option('surface');
        $profondeur = max(1, (int) $this->option('profondeur'));

        if (! in_array($surface, ['publique', 'assistant'], true)) {
            $this->error('Surface inconnue : « publique » ou « assistant ».');

            return self::FAILURE;
        }

        if (! is_array(config("database.connections.{$nom}"))) {
            $this->error("La connexion « {$nom} » n'est pas déclarée dans config/database.php.");

            return self::FAILURE;
        }

        $fichier = (string) $this->option('fichier');
        $chemin = str_starts_with($fichier, '/') ? $fichier : base_path($fichier);
        $jeu = is_file($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;
        if (! is_array($jeu) || ! is_array($jeu['cas'] ?? null)) {
            $this->error("Jeu de questions illisible : {$chemin}");

            return self::FAILURE;
        }

        try {
            DB::connection($nom)->getPdo();
        } catch (\Throwable $e) {
            $this->error('Connexion impossible : '.$e->getMessage());

            return self::FAILURE;
        }

        // Le moteur interroge la connexion par défaut : on la pointe sur celle
        // demandée pour la durée de la commande. Le cache passe en mémoire :
        // celui des embeddings et le compteur du plafond sémantique écriraient
        // sinon dans la base interrogée, que la mesure ne doit jamais modifier.
        DB::setDefaultConnection($nom);
        config(['cache.default' => 'array', 'ai.caching.embeddings.store' => 'array']);

        $this->line("Connexion : <fg=yellow>{$nom}</> · surface : <fg=yellow>{$surface}</> · ".now()->toDateTimeString());

        $prechauffe = $this->prechaufferEmbeddings($jeu['cas']);

        $lignes = [];
        $details = [];
        $durees = [];
        $mesurables = 0;
        $dansLaTolerance = 0;

        foreach ($jeu['cas'] as $index => $cas) {
            // Chaque cas se mesure indépendamment : un échec du fournisseur sur
            // le précédent ne doit pas couper le filet du suivant.
            Cache::forget(self::CLE_FILET_SEMANTIQUE_SUSPENDU);
            $debut = hrtime(true);
            $resultat = $this->mesurer($cas, $surface, $profondeur);
            $durees[] = $ms = (int) round((hrtime(true) - $debut) / 1e6);

            if ($resultat['verdict'] !== 'texte absent') {
                $mesurables++;
                if ($resultat['verdict'] === 'ok') {
                    $dansLaTolerance++;
                }
            }

            if ($this->option('detail') && $resultat['verdict'] !== 'ok') {
                $details[] = ['#'.($index + 1).' '.$cas['question'], $resultat['premiers']];
            }

            $lignes[] = [
                $index + 1,
                mb_strimwidth((string) $cas['question'], 0, 48, '…'),
                $resultat['rang'] ?? '-',
                $cas['rang_max'],
                $resultat['verdict'].(isset($cas['depend_de']) ? ' (dépend de '.strtok((string) $cas['depend_de'], ' ').')' : ''),
                $resultat['tete'],
                $this->filetSemantiqueUtilise ? 'oui' : 'non',
                $ms,
            ];
        }

        $this->table(['#', 'Question', 'Rang', 'Toléré', 'Verdict', 'En tête', 'Sém.', 'ms'], $lignes);
        foreach ($details as [$titre, $premiers]) {
            $this->newLine();
            $this->line("<fg=yellow>{$titre}</>");
            foreach ($premiers as $rang => $ligne) {
                $this->line('  '.($rang + 1).'. '.$ligne);
            }
        }

        $this->line("Dans la tolérance : <fg=green>{$dansLaTolerance}</> sur {$mesurables} cas mesurables (".(count($jeu['cas']) - $mesurables).' dont le texte est absent de cette base). Durée médiane : '.$this->mediane($durees).' ms, embedding en cache.');
        $this->line($prechauffe['durees'] === []
            ? "Embeddings : aucun (fournisseur injoignable ou non configuré : {$prechauffe['erreur']}). Le filet sémantique n'a pas couru."
            : 'Embeddings : '.count($prechauffe['durees']).' calculés, médiane '.$this->mediane($prechauffe['durees']).' ms, maximum '.max($prechauffe['durees']).' ms, '.$prechauffe['echecs'].' échec(s).');

        return self::SUCCESS;
    }

    /**
     * Calcule l'embedding de chaque question avant la mesure (délai large, deux
     * essais), pour que le moteur le trouve en cache, et chronomètre l'appel.
     *
     * @param  list<array{question: string}>  $cas
     * @return array{durees: list<int>, echecs: int, erreur: string}
     */
    private function prechaufferEmbeddings(array $cas): array
    {
        $durees = [];
        $echecs = 0;
        $erreur = '';

        foreach ($cas as $un) {
            [, $topique] = $this->parseArticleQuery($un['question']);
            if (mb_strlen($topique) < 3) {
                continue;
            }

            for ($essai = 1; $essai <= 2; $essai++) {
                $debut = hrtime(true);
                try {
                    Str::of($topique)->toEmbeddings(cache: true, timeout: 20);
                    $durees[] = (int) round((hrtime(true) - $debut) / 1e6);

                    break;
                } catch (\Throwable $e) {
                    $echecs++;
                    $erreur = mb_strimwidth($e->getMessage(), 0, 80, '…');
                }
            }
        }

        return ['durees' => $durees, 'echecs' => $echecs, 'erreur' => $erreur];
    }

    /**
     * @param  list<int>  $valeurs
     */
    private function mediane(array $valeurs): int
    {
        sort($valeurs);
        $milieu = intdiv(count($valeurs), 2);

        return $valeurs === [] ? 0 : (count($valeurs) % 2 ? $valeurs[$milieu] : intdiv($valeurs[$milieu - 1] + $valeurs[$milieu], 2));
    }

    /**
     * Rang du premier article attendu parmi les résultats, ou null.
     *
     * @param  array{question: string, attendu: list<array{document: string, articles: list<string>}>, rang_max: int}  $cas
     * @return array{rang: int|null, verdict: string, tete: string, premiers: list<string>}
     */
    private function mesurer(array $cas, string $surface, int $profondeur): array
    {
        $slugs = array_column($cas['attendu'], 'document');
        $presents = DB::table('legal_documents')
            ->whereIn('slug', $slugs)
            ->whereNull('deleted_at')
            ->where('curation_status', 'published')
            ->count();

        $resultats = $surface === 'assistant'
            ? $this->lexicalArticleContext($cas['question'], [], $profondeur)
            : $this->lexicalArticleSearch($cas['question'], [], 'relevance', $profondeur)->items();

        $tete = isset($resultats[0])
            ? mb_strimwidth($resultats[0]['document_title'], 0, 34, '…').', art. '.$resultats[0]['number']
            : '(aucun résultat)';
        $premiers = array_map(
            fn (array $ligne): string => mb_strimwidth($ligne['document_title'], 0, 50, '…').', art. '.$ligne['number'].' ('.$ligne['score'].')',
            array_slice(array_values($resultats), 0, 5),
        );

        if ($presents === 0) {
            return ['rang' => null, 'verdict' => 'texte absent', 'tete' => $tete, 'premiers' => $premiers];
        }

        foreach (array_values($resultats) as $position => $ligne) {
            foreach ($cas['attendu'] as $attendu) {
                $numeros = [(string) $ligne['number'], (string) $ligne['canonical_number']];
                if ($ligne['document_slug'] === $attendu['document'] && array_intersect($numeros, $attendu['articles']) !== []) {
                    $rang = $position + 1;

                    return ['rang' => $rang, 'verdict' => $rang <= $cas['rang_max'] ? 'ok' : 'trop bas', 'tete' => $tete, 'premiers' => $premiers];
                }
            }
        }

        return ['rang' => null, 'verdict' => "hors des {$profondeur} premiers", 'tete' => $tete, 'premiers' => $premiers];
    }
}
