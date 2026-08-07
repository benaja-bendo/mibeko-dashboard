<?php

namespace App\Console\Commands;

use App\Services\LatexArtifactCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Propose le retrait des échappements LaTeX laissés par MinerU dans le corpus
 * (`le $1^{\text{er}}$ juin`, `$\mathfrak{n}^{\circ}$ 342`, `$4^{\circ}$
 * échelon`). Ils s'affichent tels quels dans les exports PDF utilisateur :
 * `documents/pro_article_pdf.blade.php` imprime `contenu_texte` brut, sans
 * rendu mathématique. Constaté le 07/08/2026 sur l'article PREAMBULE de la
 * « DECISION DU 9 FEVRIER 1959 FIXANT LA COMPOSITION DU SENAT ».
 *
 * Même contrat que `mibeko:proposer-nettoyage-ocr`, dont elle est la jumelle :
 * LECTURE SEULE, elle écrit des fichiers à relire, jamais une ligne en base.
 * L'écriture passe ensuite par l'API (versionnée, auditée) :
 *   - `--out-contenus`      → mibeko:corriger-contenu-article --mapping
 *   - `--out-titres`        → mibeko:corriger-titres-publies --liste
 *   - `--out-signalements`  → RIEN d'automatique : à lire à l'œil.
 *
 * La conversion est délibérément timide (cf. `LatexArtifactCleaner`) : elle
 * déséchappe sans jamais réinterpréter, et laisse intact tout ce qui pourrait
 * être une vraie formule ou un symbole monétaire — le corpus contient des
 * conventions minières libellées en dollars, où un « $ » isolé est une devise,
 * pas un délimiteur. Ce qui n'a pas été converti part en signalements.
 *
 * Idempotente : relancée après correction, elle ne repropose rien puisque le
 * nettoyage est un point fixe sur un texte déjà propre. Le lot pilote se fait
 * donc simplement avec `--limit`, puis on relance sans limite.
 *
 *   php artisan mibeko:proposer-nettoyage-latex --connection=pgsql            # dev
 *   php artisan mibeko:proposer-nettoyage-latex                               # prod, lecture seule
 *   php artisan mibeko:proposer-nettoyage-latex --limit=5                     # lot pilote
 */
class ProposerNettoyageLatexCommand extends Command
{
    protected $signature = 'mibeko:proposer-nettoyage-latex
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--statut=* : Ne garder que ces curation_status (répétable ; défaut : tous)}
        {--limit= : N\'émettre que les N premières propositions de chaque liste — lot pilote}
        {--out-contenus= : Fichier JSON des contenus corrigés (défaut : storage/app/latex-contenus-<date>.json)}
        {--out-titres= : Fichier JSON des titres corrigés (défaut : storage/app/latex-titres-<date>.json)}
        {--out-signalements= : Fichier JSON des fragments laissés intacts (défaut : storage/app/latex-signalements-<date>.json)}';

    protected $description = 'Propose le retrait des échappements LaTeX de MinerU (articles et titres) — jamais d\'écriture.';

    public function __construct(private readonly LatexArtifactCleaner $nettoyeur)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $connexion = (string) $this->option('connection');

        if (str_starts_with($connexion, 'pgsql_prod')) {
            $this->warn('Prod visée : `php artisan mibeko:prod-preflight` doit avoir PROUVÉ la lecture seule avant ce diagnostic.');
            $this->newLine();
        }

        $db = DB::connection($connexion);
        $statuts = array_filter((array) $this->option('statut'));
        $limite = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

        $signalements = [];

        $contenus = $this->contenusCorriges($db, $statuts, $signalements);
        $titres = $this->titresCorriges($db, $statuts, $signalements);
        $this->noeudsSignales($db, $signalements);

        $totalContenus = count($contenus);
        $totalTitres = count($titres);

        if ($limite !== null) {
            $contenus = array_slice($contenus, 0, $limite);
            $titres = array_slice($titres, 0, $limite);
        }

        $this->resumer($contenus, $titres, $signalements, $totalContenus, $totalTitres, $limite);

        $cheminContenus = $this->ecrire('out-contenus', 'latex-contenus', $contenus);
        $cheminTitres = $this->ecrire('out-titres', 'latex-titres', $titres);
        $cheminSignalements = $this->ecrire('out-signalements', 'latex-signalements', $signalements);

        $this->newLine();
        $this->info(sprintf('%d article(s) → %s', count($contenus), $cheminContenus));
        $this->info(sprintf('%d titre(s)   → %s', count($titres), $cheminTitres));
        $this->info(sprintf('%d signalement(s) → %s', count($signalements), $cheminSignalements));

        $this->newLine();
        $this->warn('AUCUNE ÉCRITURE — à relire, puis (depuis VOTRE terminal, jamais celui d\'un agent) :');
        $this->line("  php artisan mibeko:corriger-contenu-article --mapping={$cheminContenus} --execute");
        $this->line("  php artisan mibeko:corriger-titres-publies --liste={$cheminTitres} --execute");

        return self::SUCCESS;
    }

    /**
     * Version active (`upper_inf`) de chaque article vivant d'un document
     * vivant : c'est elle que lisent l'export PDF et le site public.
     *
     * @param  list<string>  $statuts
     * @param  list<array<string, mixed>>  $signalements
     * @return list<array{id: string, document: string, motif: string, content: string}>
     */
    private function contenusCorriges(mixed $db, array $statuts, array &$signalements): array
    {
        $lignes = $db->table('article_versions as av')
            ->join('articles as a', fn ($j) => $j->on('a.id', '=', 'av.article_id')->whereNull('a.deleted_at'))
            ->join('legal_documents as d', fn ($j) => $j->on('d.id', '=', 'a.document_id')->whereNull('d.deleted_at'))
            ->when($statuts !== [], fn ($q) => $q->whereIn('d.curation_status', $statuts))
            ->whereRaw('upper_inf(av.validity_period)')
            ->whereRaw('av.contenu_texte ~ ?', [LatexArtifactCleaner::MOTIF_SQL])
            ->orderBy('d.created_at')
            ->orderBy('a.ordre_affichage')
            ->select(['a.id', 'a.numero_article', 'd.titre_officiel', 'd.curation_status', 'av.contenu_texte'])
            ->get();

        $resultat = [];

        foreach ($lignes as $ligne) {
            $analyse = $this->nettoyeur->analyser((string) $ligne->contenu_texte);
            $etiquette = Str::limit((string) $ligne->titre_officiel, 40).' art. '.$ligne->numero_article;

            if ($analyse['texte'] !== $ligne->contenu_texte && trim($analyse['texte']) !== '') {
                $resultat[] = [
                    'id' => $ligne->id,
                    'document' => $etiquette,
                    'motif' => 'Retrait des échappements LaTeX MinerU (mibeko:proposer-nettoyage-latex)',
                    'content' => $analyse['texte'],
                ];
            }

            $this->signaler($signalements, 'articles', (string) $ligne->id, $etiquette, (string) $ligne->curation_status, $analyse['refuses']);
        }

        return $resultat;
    }

    /**
     * @param  list<string>  $statuts
     * @param  list<array<string, mixed>>  $signalements
     * @return list<array{id: string, titre: string, titre_actuel: string}>
     */
    private function titresCorriges(mixed $db, array $statuts, array &$signalements): array
    {
        $documents = $db->table('legal_documents')
            ->whereNull('deleted_at')
            ->when($statuts !== [], fn ($q) => $q->whereIn('curation_status', $statuts))
            ->whereRaw('titre_officiel ~ ?', [LatexArtifactCleaner::MOTIF_SQL])
            ->orderBy('created_at')
            ->select(['id', 'titre_officiel', 'curation_status'])
            ->get();

        $resultat = [];

        foreach ($documents as $document) {
            $analyse = $this->nettoyeur->analyser((string) $document->titre_officiel);

            if ($analyse['texte'] !== $document->titre_officiel && trim($analyse['texte']) !== '') {
                $resultat[] = [
                    'id' => $document->id,
                    'titre' => trim($analyse['texte']),
                    'titre_actuel' => (string) $document->titre_officiel,
                ];
            }

            $this->signaler(
                $signalements,
                'legal_documents',
                (string) $document->id,
                Str::limit((string) $document->titre_officiel, 60),
                (string) $document->curation_status,
                $analyse['refuses'],
            );
        }

        return $resultat;
    }

    /**
     * L'intitulé d'un nœud de structure n'a AUCUN canal API (absent de la
     * validation de `StructureNodeController`) : jamais corrigé ici, seulement
     * signalé, y compris quand la conversion serait sûre.
     *
     * @param  list<array<string, mixed>>  $signalements
     */
    private function noeudsSignales(mixed $db, array &$signalements): void
    {
        $noeuds = $db->table('structure_nodes as n')
            ->join('legal_documents as d', fn ($j) => $j->on('d.id', '=', 'n.document_id')->whereNull('d.deleted_at'))
            ->whereRaw('n.titre ~ ?', [LatexArtifactCleaner::MOTIF_SQL])
            ->select(['n.id', 'n.titre', 'd.titre_officiel', 'd.curation_status'])
            ->get();

        foreach ($noeuds as $noeud) {
            $signalements[] = [
                'table' => 'structure_nodes',
                'id' => $noeud->id,
                'reference' => Str::limit((string) $noeud->titre_officiel, 40),
                'statut' => (string) $noeud->curation_status,
                'motif' => 'Intitulé de nœud : pas de canal API, correction manuelle',
                'fragments' => [(string) $noeud->titre],
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $signalements
     * @param  list<string>  $fragments
     */
    private function signaler(array &$signalements, string $table, string $id, string $reference, string $statut, array $fragments): void
    {
        $fragments = array_values(array_unique($fragments));

        if ($fragments === []) {
            return;
        }

        $signalements[] = [
            'table' => $table,
            'id' => $id,
            'reference' => $reference,
            'statut' => $statut,
            'motif' => 'Fragment non converti : formule, devise, ou bruit OCR — à trancher à l\'œil',
            'fragments' => $fragments,
        ];
    }

    /**
     * @param  list<array{id: string, document: string, motif: string, content: string}>  $contenus
     * @param  list<array{id: string, titre: string, titre_actuel: string}>  $titres
     * @param  list<array<string, mixed>>  $signalements
     */
    private function resumer(array $contenus, array $titres, array $signalements, int $totalContenus, int $totalTitres, ?int $limite): void
    {
        if ($limite !== null) {
            $this->warn(sprintf(
                'Lot pilote --limit=%d : %d article(s) sur %d et %d titre(s) sur %d.',
                $limite, count($contenus), $totalContenus, count($titres), $totalTitres,
            ));
            $this->newLine();
        }

        if ($titres !== []) {
            $this->line('<options=bold>Titres</>');
            $this->table(
                ['Avant', 'Après'],
                collect($titres)->take(10)->map(fn ($t) => [
                    mb_strimwidth($t['titre_actuel'], 0, 60, '…'),
                    mb_strimwidth($t['titre'], 0, 60, '…'),
                ])->all(),
            );
        }

        if ($contenus !== []) {
            $this->line('<options=bold>Articles — un extrait de chaque correction</>');
            $this->table(
                ['Document', 'Extrait corrigé'],
                collect($contenus)->take(10)->map(fn ($c) => [
                    mb_strimwidth($c['document'], 0, 45, '…'),
                    mb_strimwidth(str_replace("\n", ' ⏎ ', $c['content']), 0, 70, '…'),
                ])->all(),
            );
        }

        $formes = [];
        foreach ($signalements as $signalement) {
            foreach ($signalement['fragments'] as $fragment) {
                $formes[$fragment] = ($formes[$fragment] ?? 0) + 1;
            }
        }
        arsort($formes);

        if ($formes !== []) {
            $this->line('<options=bold>Fragments laissés intacts — les 10 plus fréquents</>');
            $this->table(
                ['Occurrences', 'Fragment'],
                collect($formes)->take(10)->map(fn ($n, $forme) => [$n, mb_strimwidth(str_replace("\n", ' ⏎ ', (string) $forme), 0, 70, '…')])->values()->all(),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $donnees
     */
    private function ecrire(string $option, string $prefixe, array $donnees): string
    {
        $chemin = (string) ($this->option($option) ?: storage_path("app/{$prefixe}-".now()->format('Ymd-His').'.json'));

        file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]');

        return $chemin;
    }
}
