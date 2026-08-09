<?php

namespace App\Services;

/**
 * Retire les échappements LaTeX que MinerU laisse dans le texte extrait.
 *
 * MinerU rend en mode mathématique tout ce qu'il prend pour un exposant :
 * « le 1er juin » ressort en `le $1^{\text{er}}$ juin`, « 4° échelon » en
 * `$4^{\circ}$ échelon`, « n° 342 » en `$\mathfrak{n}^{\circ}$ 342`. Rien de
 * tout cela n'est une formule : c'est de la typographie ordinaire, et elle
 * s'affiche telle quelle dans les exports PDF utilisateur (le gabarit
 * `documents/pro_article_pdf.blade.php` imprime `contenu_texte` brut).
 *
 * La conversion est volontairement TIMIDE : on ne DÉSÉCHAPPE jamais on ne
 * RÉINTERPRÈTE. `$1^{\text{st}}$` devient « 1st », pas « 1er », même si le
 * corpus est francophone et que l'OCR a manifestement fauté — corriger la
 * faute serait une décision éditoriale, hors du périmètre d'un nettoyage
 * mécanique. Toute portion qui ne se réduit pas intégralement à du texte par
 * les règles ci-dessous est laissée INTACTE et signalée : mieux vaut un
 * `$\frac{a}{b}$` visible dans un PDF qu'un texte juridique dégradé en
 * silence.
 *
 * Trois garde-fous ferment les cas dangereux :
 *  1. une portion doit être sur UNE seule ligne — les `$` dépareillés d'un
 *     tableau (`$100.000.000\n$`) ne s'apparient donc jamais ;
 *  2. elle doit contenir un marqueur LaTeX (`\`, `^{`, `_{`) — sans quoi le
 *     `$` est presque toujours le symbole monétaire (« moins de 60 $ ») et
 *     le convertir l'effacerait ;
 *  3. le résultat ne doit contenir ni reste de syntaxe LaTeX, ni opérateur,
 *     ni parenthèse — ce qui ressemble encore à une formule est refusé.
 */
class LatexArtifactCleaner
{
    /**
     * Une portion `$…$` plus longue que ça n'est pas un artefact typographique
     * mais une vraie expression : refusée quoi qu'il arrive.
     */
    private const LONGUEUR_MAX = 40;

    /**
     * Commandes de mise en forme : pure décoration, leur contenu est du texte.
     * `\mathfrak` n'y figure pas — il est traité à part, cf. `decoder()`.
     *
     * @var list<string>
     */
    private const POLICES = [
        'text', 'textrm', 'textbf', 'textit', 'textsf',
        'mathrm', 'mathbf', 'mathit', 'mathsf', 'pmb', 'operatorname',
    ];

    /**
     * Marqueurs cherchés en base pour ne charger que les lignes suspectes.
     * Expression POSIX (Postgres `~`), pas PCRE.
     */
    public const MOTIF_SQL = '\$|\\\\text\{|\\\\math|\\\\pmb|\\\\frac|\^\{|_\{';

    /**
     * Texte nettoyé de ce qui pouvait l'être sûrement ; le reste est conservé.
     */
    public function nettoyer(string $texte): string
    {
        return $this->analyser($texte)['texte'];
    }

    /**
     * Nettoie et rend compte : ce qui a été converti, ce qui a été refusé.
     *
     * @return array{
     *     texte: string,
     *     convertis: list<array{avant: string, apres: string}>,
     *     refuses: list<string>,
     * }
     */
    public function analyser(string $texte): array
    {
        $convertis = [];
        $refuses = [];

        $resultat = preg_replace_callback(
            $this->motifPortion(),
            function (array $m) use (&$convertis, &$refuses): string {
                $base = $m['base'] ?? '';
                $brut = $base.$m['avant'].'$'.$m['inner'].'$'.$m['apres'];
                $clair = $this->decoder($m['inner']);

                if ($clair === null) {
                    $refuses[] = trim($brut);

                    return $brut;
                }

                // L'espace autour de la portion est celui que MinerU a inséré
                // en ouvrant le mode mathématique : on en garde un seul, sans
                // jamais coller deux mots qui étaient séparés. EXCEPTION : un
                // chiffre collé juste avant un exposant nu (rien d'autre dans
                // le `$`, cf. `$base` ci-dessus et `motifPortion()`) — MinerU
                // rend parfois « 1er » en laissant le « 1 » en texte normal et
                // en n'ouvrant le mode mathématique que pour l'exposant seul
                // (`1 $^{er}$`), contrairement au cas normal où toute la
                // portion, base comprise, est à l'intérieur du `$`
                // (`$1^{\text{er}}$`). Sans cette exception, le blanc que
                // MinerU insère à l'ouverture du mode mathématique était
                // reproduit tel quel, donnant « 1 er » au lieu de « 1er ».
                $baseCollee = $base !== '' && str_starts_with(ltrim($m['inner']), '^{');
                $avant = $baseCollee ? '' : (($m['avant'] !== '' || preg_match('/^\s/u', $clair)) ? ' ' : '');
                $apres = ($m['apres'] !== '' || preg_match('/\s$/u', $clair)) ? ' ' : '';
                $remplacement = $base.$avant.trim($clair).$apres;

                $convertis[] = ['avant' => trim($brut), 'apres' => trim($remplacement)];

                return $remplacement;
            },
            $texte
        ) ?? $texte;

        return [
            'texte' => $resultat,
            'convertis' => $convertis,
            'refuses' => array_merge($refuses, $this->marqueursHorsPortion($texte)),
        ];
    }

    /**
     * Une portion en mode mathématique, avec l'espace horizontal qui l'entoure.
     * `[^$\r\n]` interdit d'enjamber une fin de ligne : c'est ce qui protège
     * les `$` dépareillés des tableaux de chiffres.
     *
     * `(?P<base>\d)?` capture, quand il est présent, le chiffre collé
     * immédiatement avant la portion — nécessaire pour distinguer, dans le
     * callback, un exposant nu recollé à son chiffre (`1 $^{er}$`) du cas
     * général (`1$^{er}$` sans le groupe serait déjà correctement géré, mais
     * `1 $^{er}$` avec un blanc entre les deux ne l'était pas). Le chiffre
     * capturé est réémis tel quel par le callback : rien n'est perdu, il ne
     * fait que changer de groupe de capture.
     */
    private function motifPortion(): string
    {
        return '/(?P<base>\d)?(?P<avant>[^\S\r\n]*)\$(?P<inner>[^$\r\n]*)\$(?P<apres>[^\S\r\n]*)/u';
    }

    /**
     * Réduit l'intérieur d'une portion à du texte brut, ou rend `null` si la
     * moindre chose y résiste.
     */
    private function decoder(string $inner): ?string
    {
        // Sans marqueur LaTeX, ce n'est pas un artefact d'extraction : le `$`
        // est un symbole monétaire (« le US $ », « moins de 60 $ »).
        if (! preg_match('/\\\\|\^\{|_\{/u', $inner)) {
            return null;
        }

        // MinerU lit régulièrement le « n » de « n° » en gothique. Toute autre
        // lettre en `\mathfrak` relève d'une vraie notation mathématique.
        $texte = preg_replace('/\\\\mathfrak\{([nN])\}/u', '$1', $inner) ?? $inner;

        // Polices : on garde le contenu, jamais imbriqué (`[^{}]`) — une
        // imbrication laisse un `\` qui fera échouer la validation finale.
        $polices = implode('|', self::POLICES);
        for ($i = 0; $i < 4; $i++) {
            $etape = preg_replace('/\\\\(?:'.$polices.')\{([^{}]*)\}/u', '$1', $texte) ?? $texte;
            if ($etape === $texte) {
                break;
            }
            $texte = $etape;
        }

        $texte = str_replace(['\\circ', '\\%', '\\,', '\\;', '\\ '], ['°', '%', ' ', ' ', ' '], $texte);

        // « n^{o} » et « n^{os} » : l'exposant o du français « n° » et de son
        // pluriel « nos ». Traités avant l'exposant générique, qui rendrait
        // « no » — un mot, pas une abréviation de numéro.
        $texte = preg_replace('/([nN])\^\{o\}/u', '${1}°', $texte) ?? $texte;
        $texte = preg_replace('/([nN])\^\{os\}/u', '${1}os', $texte) ?? $texte;
        $texte = preg_replace('/\^\{°\}/u', '°', $texte) ?? $texte;

        // Exposant textuel court : ordinal (« 1^{er} », « 3^{e} », « 4^{th} »).
        // Jamais après une lettre : `x^{n}` est une puissance, pas un ordinal,
        // et l'aplatir en « xn » détruirait la formule.
        $texte = preg_replace('/(?<!\pL)\^\{(\pL{1,4})\}/u', '${1}', $texte) ?? $texte;

        // Une commande non reconnue (\frac, \mathbb, \rightarrow, \prime…) a
        // laissé sa barre oblique, un exposant complexe ses accolades : refus.
        if (preg_match('/[\\\\{}^_$]/u', $texte)) {
            return null;
        }

        // Jeu de caractères final restreint : ni opérateur, ni parenthèse, ni
        // astérisque. `$1^{*}$` (bruit OCR pour « 1er ») est ainsi signalé
        // plutôt que rendu « 1* ».
        if (! preg_match('/^[\pL\pN°%.,;:\'’«»\-–—\/ ]+$/u', $texte)) {
            return null;
        }

        if (trim($texte) === '' || mb_strlen($texte) > self::LONGUEUR_MAX) {
            return null;
        }

        return $texte;
    }

    /**
     * Marqueurs LaTeX qui traînent HORS de toute portion `$…$` : `$` orphelin
     * d'un tableau, `\frac` nu, exposant sans délimiteur. Jamais touchés — on
     * ne sait pas où commence ni où finit l'expression — seulement signalés.
     *
     * @return list<string>
     */
    private function marqueursHorsPortion(string $texte): array
    {
        $reste = preg_replace($this->motifPortion(), ' ', $texte) ?? $texte;

        preg_match_all('/.{0,30}(?:\\\\frac|\\\\math|\\\\pmb|\\\\text|\^\{|_\{|\$).{0,30}/u', $reste, $trouves);

        return array_values(array_map(trim(...), $trouves[0]));
    }
}
