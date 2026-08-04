<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Propose le nettoyage des artefacts d'ingestion visibles dans le corpus
 * publié : ligatures typographiques mal décodées (« Þ xant » au lieu de
 * « fixant ») et marqueur technique de pagination laissé dans le texte lu
 * (« [[MIBEKO_PAGE:14]] »).
 *
 * Contrairement à `mibeko:proposer-titres`, cette transformation est
 * déterministe et sans ambiguïté : chaque motif reconnu a UNE seule
 * correction possible (« ﬁ » → « fi », le marqueur de page retiré), il n'y a
 * rien à deviner. Elle reste néanmoins un générateur de PROPOSITIONS — écrit
 * deux fichiers à relire, jamais d'écriture directe :
 *   - `--out-titres`   : {id, titre}                     → mibeko:corriger-titres-publies
 *   - `--out-contenus` : {id, document, motif, content}  → mibeko:corriger-contenu-article
 *
 * Le marqueur `[[MIBEKO_PAGE:N]]` n'est utilisé par AUCUN code hors
 * `mibeko-python` (vérifié par recherche exhaustive dans les 4 autres dépôts
 * le 04/08/2026) : rien ne dépend de sa présence dans `contenu_texte` une
 * fois l'article structuré, il peut être retiré sans perdre de fonctionnalité.
 *
 * Lecture seule : se connecte par défaut à `pgsql_prod_ro`.
 */
class ProposerNettoyageOcrCommand extends Command
{
    /**
     * Ligature/glyphe → remplacement. `Þ`/`þ` (thorn islandais) n'a aucun
     * usage légitime dans un corpus 100 % francophone : vérifié sur plusieurs
     * occurrences en prod, il s'y substitue systématiquement à « fi »
     * (« Þ xant » = « fixant », « modiÞ er » = « modifier ») — probablement
     * une table de police corrompue à l'extraction, pas une vraie ligature.
     *
     * @var array<string, string>
     */
    private const REMPLACEMENTS = [
        "\u{FB01}" => 'fi',  // ﬁ
        "\u{FB02}" => 'fl',  // ﬂ
        "\u{FB00}" => 'ff',  // ﬀ
        "\u{FB03}" => 'ffi', // ﬃ
        "\u{FB04}" => 'ffl', // ﬄ
        "\u{00DE}" => 'fi',  // Þ
        "\u{00FE}" => 'fi',  // þ
    ];

    protected $signature = 'mibeko:proposer-nettoyage-ocr
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--out-titres= : Fichier JSON de sortie pour les titres (défaut : storage/app/nettoyage-titres-<date>.json)}
        {--out-contenus= : Fichier JSON de sortie pour les contenus (défaut : storage/app/nettoyage-contenus-<date>.json)}';

    protected $description = 'Propose le nettoyage des ligatures cassées et marqueurs de page dans le corpus publié.';

    public function handle(): int
    {
        $db = DB::connection((string) $this->option('connection'));

        $titresCorriges = $this->corrigerTitres($db);
        $contenusCorriges = $this->corrigerContenus($db);

        $cheminTitres = (string) ($this->option('out-titres')
            ?: storage_path('app/nettoyage-titres-'.now()->format('Ymd-His').'.json'));
        $cheminContenus = (string) ($this->option('out-contenus')
            ?: storage_path('app/nettoyage-contenus-'.now()->format('Ymd-His').'.json'));

        file_put_contents($cheminTitres, json_encode($titresCorriges, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($cheminContenus, json_encode($contenusCorriges, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info(sprintf('%d titre(s) à corriger → %s', count($titresCorriges), $cheminTitres));
        $this->info(sprintf('%d article(s) à corriger → %s', count($contenusCorriges), $cheminContenus));

        if ($titresCorriges !== []) {
            $this->newLine();
            $this->table(
                ['Avant', 'Après'],
                collect($titresCorriges)->take(10)->map(fn ($t) => [
                    mb_strimwidth($t['_avant'], 0, 55, '…'),
                    mb_strimwidth($t['titre'], 0, 55, '…'),
                ])->all(),
            );
        }

        $this->newLine();
        $this->warn('AUCUNE ÉCRITURE — à relire, puis :');
        $this->line("  php artisan mibeko:corriger-titres-publies --liste={$cheminTitres} --execute");
        $this->line("  php artisan mibeko:corriger-contenu-article --mapping={$cheminContenus} --execute");

        return self::SUCCESS;
    }

    /**
     * @return list<array{id: string, titre: string, _avant: string}>
     */
    private function corrigerTitres(mixed $db): array
    {
        $documents = $db->table('legal_documents')
            ->whereNull('deleted_at')
            ->where('curation_status', 'published')
            ->whereRaw("titre_officiel ~ '[".implode('', array_keys(self::REMPLACEMENTS))."]'")
            ->select(['id', 'titre_officiel'])
            ->get();

        $resultat = [];
        foreach ($documents as $document) {
            $corrige = $this->nettoyer((string) $document->titre_officiel);
            if ($corrige !== $document->titre_officiel) {
                $resultat[] = ['id' => $document->id, 'titre' => $corrige, '_avant' => $document->titre_officiel];
            }
        }

        return $resultat;
    }

    /**
     * @return list<array{id: string, document: string, motif: string, content: string}>
     */
    private function corrigerContenus(mixed $db): array
    {
        $motifLigatures = '['.implode('', array_keys(self::REMPLACEMENTS)).']';

        $articles = $db->table('article_versions as av')
            ->join('articles as a', function ($join) {
                $join->on('a.id', '=', 'av.article_id')->whereNull('a.deleted_at');
            })
            ->join('legal_documents as ld', function ($join) {
                $join->on('ld.id', '=', 'a.document_id')
                    ->whereNull('ld.deleted_at')
                    ->where('ld.curation_status', 'published');
            })
            ->whereRaw('upper_inf(av.validity_period)')
            ->where(function ($q) use ($motifLigatures) {
                $q->whereRaw('av.contenu_texte ~ ?', [$motifLigatures])
                    ->orWhere('av.contenu_texte', 'like', '%[[MIBEKO_PAGE:%');
            })
            ->select(['a.id', 'a.numero_article', 'ld.titre_officiel', 'av.contenu_texte'])
            ->get();

        $resultat = [];
        foreach ($articles as $article) {
            $corrige = $this->nettoyer((string) $article->contenu_texte);
            if ($corrige !== $article->contenu_texte && trim($corrige) !== '') {
                $resultat[] = [
                    'id' => $article->id,
                    'document' => mb_strimwidth((string) $article->titre_officiel, 0, 40, '…').' art. '.$article->numero_article,
                    'motif' => 'Nettoyage ligature/marqueur de page (mibeko:proposer-nettoyage-ocr)',
                    'content' => $corrige,
                ];
            }
        }

        return $resultat;
    }

    private function nettoyer(string $texte): string
    {
        // Marqueur de pagination technique : sur sa propre ligne dans le texte
        // ingéré, jamais consommé par aucun code hors mibeko-python (vérifié).
        $texte = preg_replace('/\n?\s*\[\[MIBEKO_PAGE:\d+\]\]\s*\n?/u', "\n", $texte) ?? $texte;

        // Ligatures : le glyphe est suivi d'un espace parasite introduit par
        // l'extraction (« Þ xant », « ﬁ nances ») — l'espace disparaît avec lui.
        foreach (self::REMPLACEMENTS as $glyphe => $remplacement) {
            $texte = preg_replace('/'.preg_quote($glyphe, '/').'\s?/u', $remplacement, $texte) ?? $texte;
        }

        // Réduit les lignes vides laissées par le retrait du marqueur de page,
        // sans toucher aux sauts de ligne significatifs du texte juridique.
        $texte = preg_replace('/\n{3,}/u', "\n\n", $texte) ?? $texte;

        return trim($texte);
    }
}
