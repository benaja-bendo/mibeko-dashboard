<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Propose un titre complet pour les documents publiés dont le titre s'arrête
 * en plein milieu d'une phrase (« … portant », « … fixant »…).
 *
 * Volontairement restreint à CE seul cas — un titre qui s'arrête sur
 * « portant »/« fixant »/… est structurellement incomplet, sans ambiguïté.
 * Un titre qui s'arrête simplement sur un point, une virgule ou un
 * point-virgule est un signal beaucoup plus faible : il est souvent déjà une
 * phrase complète et correcte (juste courte), auquel cas lui recoller quoi
 * que ce soit l'abîme au lieu de le réparer — cas vérifié en production le
 * 04/08/2026 sur 6 documents dont le titre, déjà complet, s'est retrouvé
 * pollué par du bruit de pagination JO (« Page 1095 », « # MINISTERE… ») pris
 * faute de mieux comme suite du titre. Voir aussi le filtre anti-bruit
 * ci-dessous, qui reste une seconde ligne de défense pour ce cas-ci.
 *
 * Ne devine rien à l'aveugle : la suite manquante existe presque toujours
 * dans le premier article du document lui-même, entre la fin du titre et le
 * début des visas (« Vu la Constitution… ») ou de la formule d'autorité
 * (« Le Président de la République, » / « LE MINISTRE…, »). Cette commande
 * recolle les deux, un score de confiance mesure à quel point la coupure a
 * trouvé un repère net.
 *
 * Ne fait AUCUNE écriture, jamais : c'est un générateur de PROPOSITIONS, pas
 * un correcteur automatique — une suggestion tirée d'un texte OCR n'est pas
 * fiable à 100 %, et le titre est un champ public (SEO, fil d'Ariane). Le
 * fichier produit (--out) est une table de correspondance relue par un
 * humain (id, titre) : `mibeko:corriger-titres-publies` applique le lot une
 * fois relu, en PATCHant `titre_officiel` via l'API (jamais le slug). Ne
 * PAS utiliser `mibeko:corriger-titres-jo` pour ce fichier : cette commande
 * cible des documents en BROUILLON et ignore volontairement tout document
 * publié — ces documents-ci sont tous publiés, par construction de la
 * requête ci-dessous.
 *
 * Lecture seule : se connecte par défaut à `pgsql_prod_ro`.
 */
class ProposerTitresCommand extends Command
{
    /** Longueur maximale ajoutée au titre — au-delà, la coupure a probablement raté son repère. */
    private const LONGUEUR_MAX_AJOUT = 220;

    protected $signature = 'mibeko:proposer-titres
        {--connection=pgsql_prod_ro : Connexion cible (lecture seule)}
        {--out= : Fichier JSON de sortie (défaut : storage/app/titres-proposes-<date>.json)}
        {--limit=0 : Limite le nombre de documents traités (0 = tous)}';

    protected $description = 'Propose, sans rien écrire, un titre complet pour les documents publiés au titre tronqué.';

    public function handle(): int
    {
        $connexion = (string) $this->option('connection');
        $db = DB::connection($connexion);

        $requete = $db->table('legal_documents as ld')
            ->join('articles as a', function ($join) {
                $join->on('a.document_id', '=', 'ld.id')->whereNull('a.deleted_at');
            })
            ->join('article_versions as av', function ($join) {
                $join->on('av.article_id', '=', 'a.id')->whereRaw('upper_inf(av.validity_period)');
            })
            ->whereNull('ld.deleted_at')
            ->where('ld.curation_status', 'published')
            // Seul le cas non ambigu : un titre qui s'arrête sur un connecteur
            // est structurellement incomplet. Un titre qui s'arrête juste sur
            // une ponctuation de phrase est souvent déjà correct — l'inclure
            // a produit des titres pollués en prod (voir docblock).
            ->whereRaw("ld.titre_officiel ~* '(portant|fixant|relatif|approuvant|modifiant|complétant|créant)\\s*$'")
            // Le premier article réel (le plus petit ordre d'affichage) : sur un
            // arrêté/décret unitaire c'est le texte du dispositif, pas un
            // « PREAMBULE » de code — cette commande cible les actes courts.
            ->whereRaw('a.ordre_affichage = (
                select min(a2.ordre_affichage) from articles a2
                where a2.document_id = ld.id and a2.deleted_at is null
            )')
            ->select(['ld.id', 'ld.slug', 'ld.titre_officiel', 'av.contenu_texte'])
            ->orderBy('ld.titre_officiel');

        $limite = (int) $this->option('limit');
        if ($limite > 0) {
            $requete->limit($limite);
        }

        $documents = $requete->get();

        $propositions = [];
        $sansRepere = 0;

        foreach ($documents as $document) {
            $incipit = $this->extraireIncipit((string) $document->contenu_texte);

            if ($incipit === null) {
                $sansRepere++;

                continue;
            }

            $titreActuel = rtrim((string) $document->titre_officiel, " \t.,;");
            $separateur = str_ends_with($titreActuel, '.') || preg_match('/[.;,]$/', $titreActuel) ? ' ' : ' ';
            $titrePropose = trim($titreActuel.$separateur.$incipit);

            $propositions[] = [
                'id' => $document->id,
                'slug' => $document->slug,
                'titre_actuel' => $document->titre_officiel,
                'titre' => $titrePropose,
                'confiance' => mb_strlen($incipit) <= 140 ? 'haute' : 'a verifier',
            ];
        }

        $chemin = (string) ($this->option('out')
            ?: storage_path('app/titres-proposes-'.now()->format('Ymd-His').'.json'));

        file_put_contents($chemin, json_encode($propositions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->table(
            ['Titre actuel', 'Titre proposé', 'Confiance'],
            collect($propositions)->take(15)->map(fn ($p) => [
                mb_strimwidth($p['titre_actuel'], 0, 42, '…'),
                mb_strimwidth($p['titre'], 0, 46, '…'),
                $p['confiance'],
            ])->all(),
        );

        $this->newLine();
        $this->info(sprintf(
            '%d document(s) au titre tronqué. %d proposition(s) écrite(s) dans %s (%d sans repère net, écartés).',
            $documents->count(),
            count($propositions),
            $chemin,
            $sansRepere,
        ));
        $this->warn('AUCUNE ÉCRITURE — ce sont des propositions à relire. '
            .'Ces documents sont PUBLIÉS : une fois le fichier corrigé à la main, '
            .'utiliser mibeko:corriger-titres-publies (PATCH API, titre seulement, ne touche pas au slug) — '
            .'PAS mibeko:corriger-titres-jo, qui ignore volontairement les documents publiés : '
            .'php artisan mibeko:corriger-titres-publies --liste='.$chemin.' --execute');

        return self::SUCCESS;
    }

    /**
     * Isole la suite manquante du titre dans le texte du premier article :
     * tout ce qui précède le premier repère de visa/autorité, borné en
     * longueur pour ne jamais proposer un roman entier.
     */
    private function extraireIncipit(string $contenu): ?string
    {
        $contenu = trim($contenu);

        if ($contenu === '') {
            return null;
        }

        // Repères de fin d'incipit, dans l'ordre où ils apparaissent
        // généralement : la formule d'autorité (« Le Président… », « Le garde
        // des sceaux… », « LE MINISTRE…, ») précède presque toujours les visas
        // (« Vu la Constitution… »), mais l'un des deux peut manquer. Le mot
        // qui suit « Le »/« La » n'est PAS toujours capitalisé (« Le garde des
        // sceaux » — un titre de fonction, pas un nom propre) : le repère ne
        // porte que sur le début de ligne, pas sur la casse de la suite.
        // `(?:^|\n)` couvre aussi le cas où l'article commence DIRECTEMENT par
        // la formule d'autorité, sans texte d'objet avant — l'incipit coupé à
        // la position 0 sera alors vide, donc écarté plus bas (rien à recoller).
        //
        // Pour une LOI, le préambule se termine typiquement par « L'Assemblée
        // nationale [et le Sénat] a/ont délibéré et adopté, … » avant le
        // dispositif — cette formule d'adoption n'a pas de « Vu »/« Le X, »
        // avant elle dans ~20 documents vérifiés en production le 04/08/2026,
        // et se retrouvait donc incluse dans le titre proposé.
        $motifsCoupure = [
            '/(?:^|\n)\s*(LE|LA|Le|La)\b/u',
            '/\bVu\s+(la|l[\'’]|le|les)\b/u',
            '/\bConsidérant\b/u',
            '/L[\'’]Assembl[ée]/iu',
            '/\bSur\s+la\s+proposition\b/iu',
        ];

        $position = null;
        foreach ($motifsCoupure as $motif) {
            if (preg_match($motif, $contenu, $matches, PREG_OFFSET_CAPTURE)) {
                $offset = $matches[0][1];
                if ($position === null || $offset < $position) {
                    $position = $offset;
                }
            }
        }

        $incipit = $position !== null ? substr($contenu, 0, $position) : $contenu;

        // Nettoyage : retours à la ligne OCR en espaces simples, espaces multiples réduits.
        $incipit = trim((string) preg_replace('/\s+/u', ' ', $incipit));
        $incipit = rtrim($incipit, " \t,;:-–—");

        if ($incipit === '' || mb_strlen($incipit) > self::LONGUEUR_MAX_AJOUT) {
            return null;
        }

        // Filet anti-bruit : quand l'article choisi n'a pas de vraie suite
        // (son texte s'arrête net, ou c'est un fragment de pagination du JO
        // plutôt que le dispositif lui-même), aucun repère de visa n'existe
        // et l'incipit peut être la fin de page qui suit — numéro de page,
        // en-tête de rubrique du JO (« Actes en abrégé », un intitulé de
        // ministère tout en capitales…). Cas vérifié en production le
        // 04/08/2026 : rejeter plutôt que polluer un titre déjà correct.
        if (preg_match('/\bPage\s*[:.]?\s*\d+\b/iu', $incipit)
            || preg_match('/\bActes?\s+en\s+abr[ée]g[ée]/iu', $incipit)
            || preg_match('/(?:^|\s)#\s/u', $incipit)
            || preg_match('/\b[A-ZÀ-Ý]{4,}(?:\s+[A-ZÀ-Ý]{2,}){2,}/u', $incipit)) {
            return null;
        }

        return $incipit;
    }
}
