<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Propose une résolution des signalements `article_manquant` (lecture seule),
 * puis résout par lot ceux jugés fiables via l'API.
 *
 * Un signalement `article_manquant` dit « les numéros X à Y manquent » sans
 * jamais expliquer pourquoi — ce peut être une vraie perte d'extraction (page
 * sautée) ou un trou déjà présent dans la source (article abrogé, non
 * reproduit dans une édition consolidée). Le discriminant validé cette
 * semaine sur le Code civil et généralisé ici : si l'article qui précède le
 * trou et celui qui le suit sont sur la même page source (ou une page
 * adjacente), le trou existe déjà dans le PDF — ce n'est pas une perte.
 *
 * La description ne porte que le texte « Article(s) X-Y absent(s) (N
 * numéro(s)). » — `article_id`/`anchor`/`suggestion` sont vides pour ce type
 * de signalement (vérifié en base le 03/08/2026), d'où le passage par une
 * regex plutôt que par une colonne structurée.
 *
 *   php artisan mibeko:resoudre-article-manquant --connection=pgsql_prod_ro --rapport=storage/app/rapport.json
 *   php artisan mibeko:resoudre-article-manquant --liste-resolutions=storage/app/a-resoudre.json --execute
 */
class ResoudreArticleManquantCommand extends Command
{
    private const MOTIF_PLAGE = '/Article\(s\)\s+(\d+)(?:-(\d+))?\s+absent/';

    protected $signature = 'mibeko:resoudre-article-manquant
        {--connection=pgsql_prod_ro : Connexion à interroger pour le diagnostic}
        {--tolerance=1 : Écart de pages toléré pour classer un trou « déjà dans la source »}
        {--rapport= : Fichier où écrire le rapport complet (toutes classifications)}
        {--liste-resolutions= : Fichier où écrire les IDs des flags proposés à résolution (auto + tolérant)}
        {--base-url=https://api.mibeko.fr/api/v1 : Racine de l\'API visée}
        {--execute : Résout réellement les flags auto + tolérant via l\'API. Sans cette option, diagnostic seul.}';

    protected $description = 'Diagnostique puis résout par lot les signalements article_manquant fiables (lecture seule par défaut).';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');

        if ($execute) {
            return $this->executer();
        }

        return $this->diagnostiquer();
    }

    private function diagnostiquer(): int
    {
        $connexion = (string) $this->option('connection');
        $tolerance = max(0, (int) $this->option('tolerance'));

        $flags = DB::connection($connexion)
            ->table('curation_flags')
            ->where('type_probleme', 'article_manquant')
            ->where('resolved', false)
            ->where('severity', 'blocking')
            ->select(['id', 'document_id', 'description'])
            ->get();

        if ($flags->isEmpty()) {
            $this->warn('Aucun signalement article_manquant ouvert.');

            return self::SUCCESS;
        }

        $classes = $flags->map(fn ($flag) => $this->classer($connexion, $flag, $tolerance));

        $this->afficherLeBilan($classes);
        $this->ecrire((string) $this->option('rapport'), $classes->values()->all(), 'Rapport complet');

        $retenus = $classes->whereIn('classe', ['auto', 'tolerant'])->pluck('id')->values()->all();
        $this->ecrire((string) $this->option('liste-resolutions'), $retenus, 'Liste des flags proposés à résolution');

        return self::SUCCESS;
    }

    /**
     * @param  object{id: string, document_id: string, description: string}  $flag
     */
    private function classer(string $connexion, object $flag, int $tolerance): array
    {
        if (preg_match(self::MOTIF_PLAGE, $flag->description, $m) !== 1) {
            return $this->resultat($flag, 'suspect', 'description hors format attendu, non parsable');
        }

        $debut = (int) $m[1];
        $fin = isset($m[2]) ? (int) $m[2] : $debut;

        if ($debut <= 1) {
            return $this->resultat($flag, 'suspect', 'trou en tout début de document, pas d\'article encadrant avant');
        }

        $avant = $this->trouverArticle($connexion, $flag->document_id, (string) ($debut - 1));
        $apres = $this->trouverArticle($connexion, $flag->document_id, (string) ($fin + 1));

        if ($avant === null || $apres === null) {
            $manquant = $avant === null && $apres === null ? 'ni l\'article avant ni l\'article après'
                : ($avant === null ? 'l\'article avant le trou' : 'l\'article après le trou');

            return $this->resultat($flag, 'suspect', "introuvable en base : {$manquant} (numérotation non contiguë, à vérifier au cas par cas)");
        }

        $pageAvant = $this->pageDe($connexion, $avant->id);
        $pageApres = $this->pageDe($connexion, $apres->id);

        if ($pageAvant === null || $pageApres === null) {
            return $this->resultat($flag, 'semi-auto', 'page non enregistrée pour au moins un des deux articles encadrants — contrôle par lot requis');
        }

        $ecart = abs($pageAvant - $pageApres);

        if ($ecart === 0) {
            return $this->resultat($flag, 'auto', "articles {$debut} et {$fin} : encadrants tous deux page {$pageAvant} — trou déjà dans la source");
        }

        if ($ecart <= $tolerance) {
            return $this->resultat($flag, 'tolerant', "encadrants pages {$pageAvant} et {$pageApres} (écart {$ecart} ≤ tolérance {$tolerance}) — probable coupure de page à l'extraction");
        }

        return $this->resultat($flag, 'suspect', "encadrants pages {$pageAvant} et {$pageApres} (écart {$ecart}) — saut trop important, perte d'extraction possible");
    }

    private function resultat(object $flag, string $classe, string $raison): array
    {
        return [
            'id' => $flag->id,
            'document_id' => $flag->document_id,
            'description' => $flag->description,
            'classe' => $classe,
            'raison' => $raison,
        ];
    }

    private function trouverArticle(string $connexion, string $documentId, string $numero): ?object
    {
        return DB::connection($connexion)
            ->table('articles')
            ->where('document_id', $documentId)
            ->where('numero_article', $numero)
            ->whereNull('deleted_at')
            ->select(['id'])
            ->first();
    }

    private function pageDe(string $connexion, string $articleId): ?int
    {
        $page = DB::connection($connexion)
            ->table('article_versions')
            ->where('article_id', $articleId)
            ->orderBy('created_at')
            ->value(DB::raw("source_locator->>'page'"));

        return $page !== null && is_numeric($page) ? (int) $page : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $classes
     */
    private function afficherLeBilan(Collection $classes): void
    {
        $parClasse = $classes->countBy('classe');

        $this->newLine();
        $this->table(
            ['Classe', 'Signalements', 'Sens'],
            [
                ['auto', $parClasse->get('auto', 0), 'même page des deux côtés — trou déjà dans la source'],
                ['tolerant', $parClasse->get('tolerant', 0), 'pages adjacentes — coupure de page probable à l\'extraction'],
                ['semi-auto', $parClasse->get('semi-auto', 0), 'page manquante d\'un côté — contrôle par lot requis'],
                ['suspect', $parClasse->get('suspect', 0), 'écart important ou article introuvable — à vérifier individuellement'],
            ],
        );

        $this->newLine();
        $this->line(($parClasse->get('auto', 0) + $parClasse->get('tolerant', 0))
            .' signalement(s) proposé(s) à résolution automatique par lot (auto + tolérant).');
    }

    private function ecrire(string $chemin, array $donnees, string $libelle): void
    {
        if ($chemin === '') {
            return;
        }

        file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        $this->newLine();
        $this->info("{$libelle} : {$chemin} (".count($donnees).' entrée(s))');
    }

    private function executer(): int
    {
        $chemin = (string) $this->option('liste-resolutions');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('Option --liste-resolutions obligatoire avec --execute : chemin d\'un fichier JSON lisible (généré par un passage préalable sans --execute).');

            return self::FAILURE;
        }

        $ids = json_decode((string) file_get_contents($chemin), true);

        if (! is_array($ids) || $ids === []) {
            $this->error('La liste de résolutions est vide ou n\'est pas un tableau JSON.');

            return self::FAILURE;
        }

        $jeton = (string) env('MIBEKO_API_TOKEN', '');

        if ($jeton === '') {
            $this->error('MIBEKO_API_TOKEN absent du shell. À exporter à la main, jamais dans un fichier.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $resolus = 0;

        foreach (array_chunk($ids, 200) as $lot) {
            $reponse = Http::withToken($jeton)->acceptJson()->timeout(30)
                ->post("{$baseUrl}/admin/flags/bulk", ['ids' => $lot, 'action' => 'resolve']);

            if ($reponse->failed()) {
                $this->error('Lot refusé : '.$this->motif($reponse));

                return self::FAILURE;
            }

            $affected = (int) data_get($reponse->json(), 'data.affected', 0);
            $resolus += $affected;
            $this->line('<fg=green>✓</> lot de '.count($lot).' identifiants — '.$affected.' réellement résolus (ids déjà disparus ignorés)');
        }

        $this->newLine();
        $this->info("{$resolus} signalement(s) résolu(s) au total.");

        return self::SUCCESS;
    }

    private function motif(Response $reponse): string
    {
        $corps = $reponse->json();

        return (string) (data_get($corps, 'message') ?: $reponse->status());
    }
}
