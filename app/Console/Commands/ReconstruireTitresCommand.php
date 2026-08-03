<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Propose la reconstruction des intitulés tronqués (lecture seule).
 *
 * Deux défauts d'ingestion distincts, mesurés le 03/08/2026 sur 305 drafts :
 * une césure de fin de ligne jamais recollée (« Arrêté n° 1670 … portant
 * agré- ») et un résidu LaTeX de conversion OCR (« Décret $\mathbf{n}^{\circ}$
 * 59-168 … »). Dans les deux cas le contenu réel existe déjà : soit dans le
 * PREAMBULE de l'acte (qui porte la suite du titre avant la formule de
 * qualité du signataire — « Le Premier ministre, », « Le garde des sceaux,
 * ministre de la justice, », etc.), soit dans le titre lui-même une fois le
 * résidu LaTeX nettoyé.
 *
 * Le PREAMBULE n'a pas de séparateur fiable entre « fin du titre » et
 * « début des visas » — pas toujours de point avant « Le ministre… » — d'où
 * la détection par formule de qualité plutôt que par ponctuation. Vérifié
 * manuellement sur 17 documents réels avant d'écrire cette commande : la
 * frontière est fiable, la césure peut réapparaître À L'INTÉRIEUR du
 * PREAMBULE (pas seulement à la jonction titre/PREAMBULE), d'où une
 * dé-hyphénation appliquée uniformément sur toute la séquence de lignes.
 *
 * Cette commande ne PROPOSE que — la mapping produite alimente
 * `mibeko:corriger-titres-jo`, seule habilitée à écrire (transaction, fichier
 * de retour arrière, écarte les documents publiés).
 *
 *   php artisan mibeko:reconstruire-titres --connection=pgsql_prod_ro \
 *       --rapport=storage/app/rapport.json --mapping=storage/app/mapping.json
 *   php artisan mibeko:corriger-titres-jo --mapping=storage/app/mapping.json --connection=pgsql_prod_rw --execute
 */
class ReconstruireTitresCommand extends Command
{
    private const CANDIDAT = '[-­]$|\$|\\\\math|\^\{';

    private const DEBUT_CANONIQUE = '^\s*(loi|décret|decret|arrêté|arrete|ordonnance|décision|decision|code|règlement|reglement|acte)\b';

    private const MOTIF_NUMERO_OU_DATE = 'n[°ºo]\s*\d|\d{1,3}[\-\/]\d{2,4}|\b(1[5-9]|20)\d{2}\b';

    /**
     * Formules qui ouvrent les visas — la frontière titre/corps. Pour un
     * décret ou un arrêté, c'est la qualité du signataire (« Le Ministre… »).
     * Pour une loi, le PREAMBULE s'ouvre différemment, sur la formule de
     * promulgation (« L'Assemblée nationale … a délibéré et adopté »),
     * jamais sur une qualité de signataire — sans elle dans cette liste, la
     * formule entière se retrouvait accolée à des titres de loi (constaté en
     * prod avant l'ajout de cette entrée).
     */
    private const FORMULES_AUTORITE = [
        'le président', 'le premier ministre', 'le ministre', 'la ministre',
        'le garde des sceaux', 'le maire', 'le gouverneur', 'le directeur général',
        'le secrétaire général', 'le préfet', 'sur le rapport', 'sur proposition',
        'vu ', 'vu(e)', 'vu la', 'vu le', 'vu les', 'décreté', 'decrete', 'arrête',
        "l'assemblée", 'l’assemblée', 'l assemblee', 'le sénat', 'le parlement',
    ];

    protected $signature = 'mibeko:reconstruire-titres
        {--connection=pgsql_prod_ro : Connexion à interroger (lecture seule par défaut)}
        {--rapport= : Fichier où écrire le rapport complet (proposés et suspects)}
        {--mapping= : Fichier où écrire le mapping {id,titre} des propositions retenues, au format --mapping de corriger-titres-jo}';

    protected $description = 'Propose la reconstruction des intitulés tronqués (césure, LaTeX) — lecture seule.';

    public function handle(): int
    {
        $connexion = (string) $this->option('connection');

        $candidats = DB::connection($connexion)
            ->table('legal_documents')
            ->whereNull('deleted_at')
            ->where('curation_status', 'draft')
            ->where(fn ($q) => $q->whereRaw("titre_officiel ~ '[-­]$'")
                ->orWhereRaw("titre_officiel ~ '\\$|\\\\\\\\math|\\^\\{'"))
            ->select(['id', 'titre_officiel'])
            ->get()
            ->filter(fn ($d) => preg_match('/'.self::DEBUT_CANONIQUE.'/iu', $d->titre_officiel) === 1
                && preg_match('/'.self::MOTIF_NUMERO_OU_DATE.'/u', $d->titre_officiel) === 1);

        if ($candidats->isEmpty()) {
            $this->warn('Aucun candidat (intitulé canonique, avec numéro/date, portant césure ou résidu LaTeX).');

            return self::SUCCESS;
        }

        $resultats = $candidats->map(fn ($d) => $this->reconstruire($connexion, $d));

        $this->afficherLeBilan($resultats);
        $this->ecrire((string) $this->option('rapport'), $resultats->values()->all(), 'Rapport complet');

        $mapping = $resultats->where('classe', 'propose')
            ->map(fn ($r) => ['id' => $r['id'], 'titre' => $r['nouveau_titre']])
            ->values()->all();
        $this->ecrire((string) $this->option('mapping'), $mapping, 'Mapping pour corriger-titres-jo');

        return self::SUCCESS;
    }

    private function reconstruire(string $connexion, object $document): array
    {
        // Un titre qui se termine déjà par une ponctuation forte a son objet
        // complet — ne JAMAIS lui accoler du texte du PREAMBULE, même si le
        // reste du corpus déclenche un résidu LaTeX ailleurs dans le titre.
        // Sans ce garde-fou, une loi (dont le PREAMBULE s'ouvre sur
        // « L'Assemblée … a délibéré et adopté », pas sur une formule
        // d'autorité reconnue) se voit accoler la formule de promulgation
        // entière — constaté en prod avant l'ajout de ce garde-fou.
        $sansContinuationPossible = preg_match('/[.;:!]$/u', trim($document->titre_officiel)) === 1;

        if ($sansContinuationPossible) {
            $nouveau = $this->nettoyerEtNormaliser($document->titre_officiel);

            return $this->validerEtClasser($document, $nouveau);
        }

        $preambule = DB::connection($connexion)
            ->table('articles')
            ->join('article_versions', 'article_versions.article_id', '=', 'articles.id')
            ->where('articles.document_id', $document->id)
            ->where('articles.numero_article', 'PREAMBULE')
            ->whereNull('articles.deleted_at')
            ->orderBy('article_versions.created_at')
            ->value('article_versions.contenu_texte');

        if ($preambule === null) {
            return $this->resultat($document, null, 'suspect', 'aucun PREAMBULE en base — reconstruction impossible, revue individuelle');
        }

        $lignes = preg_split('/\r\n|\r|\n/', $preambule) ?: [];
        $indexAutorite = $this->indexPremiereFormuleAutorite($lignes);
        $continuation = $indexAutorite === null ? $lignes : array_slice($lignes, 0, $indexAutorite);
        $continuation = array_values(array_filter($continuation, fn ($l) => trim($l) !== ''));

        $combine = $continuation === []
            ? $document->titre_officiel
            : $this->joindreEnDehyphenant([$document->titre_officiel, ...$continuation]);

        $nouveau = $this->nettoyerEtNormaliser($combine);

        return $this->validerEtClasser($document, $nouveau);
    }

    private function nettoyerEtNormaliser(string $texte): string
    {
        $s = $this->nettoyerLatex($texte);
        $s = preg_replace('/\s{2,}/', ' ', $s) ?? $s;
        // Un délimiteur LaTeX retiré (ex. « $A$ » → « A ») laisse un espace
        // orphelin devant la virgule ou le point qui suivait : « A , hierarchie »
        // → « A, hierarchie ». On ne touche pas à « ; » ni « : » : l'espace fine
        // qui les précède est une convention typographique française légitime,
        // pas un artefact (même règle que mibeko-site/src/lib/sanitize.ts).
        $s = preg_replace('/\s+([,.])/u', '$1', $s) ?? $s;

        return trim($s);
    }

    /**
     * @param  list<string>  $lignes
     */
    private function indexPremiereFormuleAutorite(array $lignes): ?int
    {
        foreach ($lignes as $i => $ligne) {
            $normalisee = mb_strtolower(trim($ligne));

            if ($normalisee === '') {
                continue;
            }

            foreach (self::FORMULES_AUTORITE as $formule) {
                if (str_starts_with($normalisee, $formule)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Recolle une séquence de lignes issues d'un texte justifié en PDF : une
     * ligne qui se termine par un tiret de césure se rattache SANS espace au
     * début de la ligne suivante (mot coupé), toute autre jonction prend un
     * espace. Le tiret de fin de ligne, dans ce corpus, n'a jamais d'autre
     * sens qu'une césure (vérifié manuellement sur l'échantillon de conception).
     *
     * @param  list<string>  $lignes
     */
    private function joindreEnDehyphenant(array $lignes): string
    {
        $resultat = '';

        foreach ($lignes as $i => $ligne) {
            $ligne = trim($ligne);

            if ($i === 0) {
                $resultat = $ligne;

                continue;
            }

            $resultat = preg_match('/[-­]$/u', $resultat) === 1
                ? preg_replace('/[-­]$/u', '', $resultat).$ligne
                : $resultat.' '.$ligne;
        }

        return $resultat;
    }

    /**
     * Nettoyage LaTeX ciblé sur les résidus rencontrés dans les intitulés
     * (sous-ensemble de mibeko-site/src/lib/sanitize.ts, adapté aux titres).
     */
    private function nettoyerLatex(string $texte): string
    {
        $s = $texte;

        // \bullet est ici un alias mal océrisé de \circ (« n° » écrit en exposant) :
        // à traiter AVANT le dépliage des exposants, sinon la commande brute
        // « \bullet » serait supprimée sans laisser de trace du degré.
        $s = preg_replace('/\\\\bullet/u', '\\circ', $s) ?? $s;

        // \Lambda (lettre grecque) est une confusion OCR avec le "A" latin des
        // codes de catégorie administrative (catégorie A/B/C/D) — jamais une
        // vraie lettre grecque dans ce corpus. Sans ce remplacement, la lettre
        // de catégorie disparaissait purement et simplement au nettoyage
        // (constaté en prod : « catégorie $\Lambda$ » → « catégorie », », perte
        // d'information plutôt que résidu visible).
        $s = preg_replace('/\\\\Lambda/u', 'A', $s) ?? $s;

        $s = preg_replace('/\\\\([%&#_$])/u', '$1', $s) ?? $s;
        $s = preg_replace('/\$+/u', '', $s) ?? $s;
        $s = str_replace('~', ' ', $s);

        $s = preg_replace('/\^\s*\{\s*\\\\circ\s*\}/u', '°', $s) ?? $s;
        $s = preg_replace('/\^\s*\\\\circ/u', '°', $s) ?? $s;
        $s = preg_replace('/\\\\circ/u', '°', $s) ?? $s;
        $s = preg_replace('/\^\s*\{\s*\\\\prime\s*\}/u', '′', $s) ?? $s;

        $s = preg_replace_callback('/\^\{([0-9]+)\}/u', fn ($m) => $this->exposant($m[1]), $s) ?? $s;
        $s = preg_replace_callback('/\^([0-9])(?![0-9])/u', fn ($m) => $this->exposant($m[1]), $s) ?? $s;

        do {
            $avant = $s;
            $s = preg_replace('/\\\\(?:mathrm|mathbf|mathit|mathsf|mathtt|mathcal|mathbb|mathfrak|mathnormal|text|textrm|textbf|textit|rm|bf|it)\s*\{([^{}]*)\}/u', '$1', $s) ?? $s;
        } while ($s !== $avant);

        $s = preg_replace('/[\^_]\s*\{([^{}]*)\}/u', '$1', $s) ?? $s;
        $s = preg_replace('/[\^_]\s*([^\s{}\\\\^_])/u', '$1', $s) ?? $s;
        $s = preg_replace('/\\\\[a-zA-Z]+/u', '', $s) ?? $s;
        $s = str_replace(['{', '}'], '', $s);

        return $s;
    }

    private function exposant(string $chiffres): string
    {
        $table = ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸', '9' => '⁹'];

        return implode('', array_map(fn ($c) => $table[$c] ?? $c, str_split($chiffres)));
    }

    private function validerEtClasser(object $document, string $nouveau): array
    {
        if (preg_match('/[-­]$/u', $nouveau) === 1) {
            return $this->resultat($document, $nouveau, 'suspect', 'se termine encore par une césure — le PREAMBULE ne contient pas de texte avant la formule d\'autorité');
        }

        if ($nouveau === $document->titre_officiel) {
            return $this->resultat($document, $nouveau, 'suspect', 'reconstruction identique à l\'original — rien n\'a changé, à vérifier pourquoi');
        }

        if (mb_strlen($nouveau) > 500) {
            return $this->resultat($document, $nouveau, 'suspect', 'résultat anormalement long (>500 caractères) — la frontière avec les visas n\'a probablement pas été détectée');
        }

        if (preg_match('/'.self::DEBUT_CANONIQUE.'/iu', $nouveau) !== 1 || preg_match('/'.self::MOTIF_NUMERO_OU_DATE.'/u', $nouveau) !== 1) {
            return $this->resultat($document, $nouveau, 'suspect', 'le résultat ne matche plus le motif nature d\'acte + numéro/date attendu');
        }

        // Filet de sécurité : un caractère ASCII isolé entre espaces (symbole
        // ® ou lettre seule) trahit un résidu LaTeX que le nettoyage n'a pas
        // su interpréter (ex. \text{®} ou \text{a} utilisés par l'OCR à la
        // place de « ° ») — mieux vaut le signaler que deviner et publier un
        // artefact visible dans un intitulé. Restreint aux lettres SANS
        // accent : « à » et « y » sont des mots français légitimes d'une
        // seule lettre (constaté en prod : « à la société » faussement
        // signalé avant cette restriction).
        if (preg_match('/(?<=\s)[a-z](?=\s)|®/u', $nouveau) === 1) {
            return $this->resultat($document, $nouveau, 'suspect', 'un caractère isolé subsiste après nettoyage — résidu LaTeX non interprétable automatiquement, à corriger à la main');
        }

        return $this->resultat($document, $nouveau, 'propose', 'nature d\'acte, numéro et date préservés, plus de césure ni de résidu LaTeX');
    }

    private function resultat(object $document, ?string $nouveau, string $classe, string $raison): array
    {
        return [
            'id' => $document->id,
            'ancien_titre' => $document->titre_officiel,
            'nouveau_titre' => $nouveau,
            'classe' => $classe,
            'raison' => $raison,
        ];
    }

    private function afficherLeBilan($resultats): void
    {
        $parClasse = $resultats->countBy('classe');

        $this->newLine();
        $this->table(
            ['Classe', 'Documents'],
            [
                ['propose', $parClasse->get('propose', 0)],
                ['suspect', $parClasse->get('suspect', 0)],
            ],
        );

        $suspects = $resultats->where('classe', 'suspect');

        if ($suspects->isNotEmpty()) {
            $this->newLine();
            $this->line('Motifs des cas suspects :');

            foreach ($suspects->countBy('raison') as $raison => $n) {
                $this->line("  · {$n}× {$raison}");
            }
        }

        $this->newLine();
        $this->line('Échantillon des propositions :');

        foreach ($resultats->where('classe', 'propose')->take(6) as $r) {
            $this->line("  · {$r['ancien_titre']}");
            $this->line("    → {$r['nouveau_titre']}");
        }
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
}
