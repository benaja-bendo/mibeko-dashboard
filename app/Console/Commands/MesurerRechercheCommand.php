<?php

namespace App\Console\Commands;

use App\Traits\SearchesArticles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
 * Deux surfaces : `publique` (recherche du site et de l'application, sans filet
 * sémantique par défaut) et `assistant` (outil de l'assistant, filet sémantique
 * compris, qui a besoin d'un fournisseur d'embeddings joignable).
 */
class MesurerRechercheCommand extends Command
{
    use SearchesArticles;

    protected $signature = 'mibeko:mesurer-recherche
        {--connection=pgsql_prod_ro : Connexion à interroger (pgsql_prod_ro par défaut ; passer la connexion locale pour la copie de la production)}
        {--fichier=database/reference/recherche-reference.json : Jeu de questions de référence}
        {--surface=publique : publique (site et application) ou assistant (outil de l\'assistant)}
        {--profondeur=50 : Nombre de résultats examinés par question}';

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
        // demandée pour la durée de la commande.
        DB::setDefaultConnection($nom);

        $this->line("Connexion : <fg=yellow>{$nom}</> · surface : <fg=yellow>{$surface}</> · ".now()->toDateTimeString());

        $lignes = [];
        $durees = [];
        $mesurables = 0;
        $dansLaTolerance = 0;

        foreach ($jeu['cas'] as $index => $cas) {
            $debut = hrtime(true);
            $resultat = $this->mesurer($cas, $surface, $profondeur);
            $durees[] = $ms = (int) round((hrtime(true) - $debut) / 1e6);

            if ($resultat['verdict'] !== 'texte absent') {
                $mesurables++;
                if ($resultat['verdict'] === 'ok') {
                    $dansLaTolerance++;
                }
            }

            $lignes[] = [
                $index + 1,
                mb_strimwidth((string) $cas['question'], 0, 48, '…'),
                $resultat['rang'] ?? '-',
                $cas['rang_max'],
                $resultat['verdict'].(isset($cas['depend_de']) ? ' (dépend de '.strtok((string) $cas['depend_de'], ' ').')' : ''),
                $resultat['tete'],
                $ms,
            ];
        }

        $this->table(['#', 'Question', 'Rang', 'Toléré', 'Verdict', 'En tête', 'ms'], $lignes);
        $this->line("Dans la tolérance : <fg=green>{$dansLaTolerance}</> sur {$mesurables} cas mesurables (".(count($jeu['cas']) - $mesurables).' dont le texte est absent de cette base). Durée médiane : '.$this->mediane($durees).' ms.');

        return self::SUCCESS;
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
     * @return array{rang: int|null, verdict: string, tete: string}
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

        if ($presents === 0) {
            return ['rang' => null, 'verdict' => 'texte absent', 'tete' => $tete];
        }

        foreach (array_values($resultats) as $position => $ligne) {
            foreach ($cas['attendu'] as $attendu) {
                $numeros = [(string) $ligne['number'], (string) $ligne['canonical_number']];
                if ($ligne['document_slug'] === $attendu['document'] && array_intersect($numeros, $attendu['articles']) !== []) {
                    $rang = $position + 1;

                    return ['rang' => $rang, 'verdict' => $rang <= $cas['rang_max'] ? 'ok' : 'trop bas', 'tete' => $tete];
                }
            }
        }

        return ['rang' => null, 'verdict' => "hors des {$profondeur} premiers", 'tete' => $tete];
    }
}
