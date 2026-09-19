<?php

namespace App\Services\Curation;

use Illuminate\Support\Str;

/**
 * Lit le numéro d'un acte DANS son titre officiel, et le ramène à la forme
 * normalisée que porte la colonne `numero_acte` (migration du 19/09/2026).
 *
 * Pourquoi cette classe existe : la décision du 19/09/2026 fait dériver l'URL
 * canonique d'un texte de sa CITATION (type, numéro, date de signature), qui
 * ne change jamais, au lieu de son titre, que la curation corrige. Le numéro
 * n'était jusqu'ici lisible que dans le titre — texte libre, issu de l'OCR.
 * L'extraire une fois, le faire relire, puis le stocker avec sa provenance est
 * la seule façon de ne pas refonder l'identité publique sur du texte libre.
 *
 * Deux règles de conduite héritées du chantier `libelle_descriptif` (16/08) :
 *
 * 1. **Rien n'est écrit ici.** La sortie est une PROPOSITION relue par un
 *    humain (`mibeko:proposer-numeros` → relecture → `mibeko:appliquer-numeros`).
 * 2. **Le titre officiel n'est jamais touché.** On y lit, on n'y écrit pas.
 *
 * Et une règle propre à ce chantier : **un numéro douteux n'est pas un
 * numéro**. Tout ce qui ne rentre pas dans la forme attestée est rejeté avec
 * son motif, pour remonter en `curation_flags` — jamais forcé dans la colonne.
 * Un mauvais numéro ne se voit pas : il fabrique une URL canonique fausse et
 * une collision silencieuse avec le vrai porteur de cette citation.
 */
class NumeroActeExtractor
{
    /** Largeur de la colonne `numero_acte`. Au-delà, ce n'est plus un numéro. */
    public const LONGUEUR_MAX = 60;

    /** Numéro extrait du titre officiel par cette classe, puis relu. */
    public const SOURCE_TITRE = 'titre';

    /** Numéro saisi à la main par un éditeur (corps de l'acte, JO papier). */
    public const SOURCE_MANUEL = 'manuel';

    /**
     * Le numéro, dans un titre, s'arrête au premier de ces mots.
     *
     * « Décret n° 2025-240 **du** 20 juin 2025 », « Arrêté n° 3497 **portant**
     * nomination… » : la date et l'objet suivent immédiatement le numéro, sans
     * ponctuation dans la moitié des cas. Sans cette frontière, le numéro
     * avalerait la date entière, puis l'objet.
     */
    private const FIN_DU_NUMERO = 'du|de|des|le|la|en\s+date|portant|fixant|modifiant|compl[ée]tant'
        .'|relatif|relative|approuvant|autorisant|cr[ée]ant|instituant|abrogeant|d[ée]terminant'
        .'|d[ée]finissant|organisant|prorogeant|rendant|convoquant|nommant|d[ée]clarant|allouant'
        .'|accordant|attribuant|retirant|suspendant|renouvelant|transf[ée]rant|[ée]rigeant|classant'
        .'|homologuant|r[ée]glementant|interdisant|sur|et|pris|portants?';

    /**
     * Ce qui peut désigner le type d'acte AVANT son numéro.
     *
     * Bornage repris de `LibelleDescriptifExtractor::estActeEnAbrege()`, pour
     * la même raison : un titre cite souvent un AUTRE texte (« … modifiant le
     * décret n° 2007-274 du 21 mai 2007 »). Le numéro de l'acte lui-même est
     * celui qui suit sa propre désignation, en tête de titre — jamais un
     * numéro trouvé au milieu d'une phrase.
     *
     * Insensible à la casse : les textes de 1958-59 impriment tout en
     * capitales (« LOI N° 21-2018 »), et l'OCR rend parfois le degré par un
     * « o » (« NO 21 ») — d'où `n[°ºo]`, sûr ici parce qu'un chiffre est exigé
     * juste derrière. « NUMERO 1 » en toutes lettres (deux lois
     * constitutionnelles de 1959) est accepté au même titre.
     */
    private const DESIGNATION_EN_TETE = '/^(\p{L}[\p{L}\'’ \-]{0,40}?)n(?:[°ºo]|um[ée]ro)\s*(?=\d)/iu';

    /**
     * Ce qui, dans la désignation, prouve qu'on lit le numéro d'un AUTRE acte.
     *
     * « Arrêté portant application du décret n° 2007-274 du 21 mai 2007 » : le
     * seul « n° » du titre est celui du texte cité, et le bornage en tête ne
     * suffit pas à l'écarter — 37 caractères seulement séparent le début du
     * titre de ce numéro. Prendre ce numéro donnerait à l'arrêté l'identité du
     * décret : une URL canonique fausse, et une collision silencieuse avec le
     * vrai décret 2007-274.
     *
     * Testé sur ce qui SUIT le premier mot, pour que « Décret », « Arrêté »,
     * « Loi » en tête ne se déclenchent pas eux-mêmes. Une désignation
     * composée reste donc acceptée (« Décret en Conseil des ministres n° … »,
     * « Loi organique n° … », « Arrêté ministériel n° … »).
     *
     * Le sens du doute est assumé : un numéro manqué devient un signalement de
     * curation, un numéro faux devient une URL publique fausse.
     */
    private const DESIGNATION_CITANT_UN_AUTRE_ACTE = '/\b(?:portant|fixant|modifiant|compl[ée]tant|relatif'
        .'|relative|approuvant|autorisant|abrogeant|application|susvis[ée]e?s?|pris|vu|d[ée]cret|arr[êe]t[ée]'
        .'|loi|ordonnance|d[ée]cision|acte|convention|circulaire|r[èe]glement)\b/iu';

    /**
     * Forme normalisée attestée : commence par un chiffre, puis des groupes
     * alphanumériques séparés par `-` ou `/`.
     *
     * Couvre les quatre familles vues dans le corpus : `3497` (simple),
     * `2025-240` (année-rang), `69-429` (année courte), `80-550/ETR-SGDAAPDP`
     * (avec code de service), plus les suffixes `14bis/59`. Tout le reste est
     * rejeté et signalé, pas forcé.
     */
    private const FORME_NORMALISEE = '/^\d[\p{L}\d]*(?:[-\/][\p{L}\d]+)*$/u';

    /** Forme canonique du JO : chiffres et tirets seulement (`2025-240`, `3497`). */
    private const FORME_CANONIQUE = '/^\d+(?:-\d+)*$/';

    /**
     * Type d'acte que le premier mot d'un titre annonce sans ambiguïté.
     *
     * Sert au croisement avec `type_code` : un titre qui commence par « Décret »
     * sur un document typé ARR n'est pas un arrêté au titre fantaisiste, c'est
     * presque toujours une phrase de CORPS promue en titre — « Décret n° 2007-274
     * du 21 mai 2007 susvisé, il est … » — dont le numéro appartient à un autre
     * acte. Les mots absents d'ici (avis, rectificatif, convention…) n'annoncent
     * rien d'assez sûr pour contredire le type.
     */
    private const TYPE_PAR_MOT_DE_TETE = [
        'decret' => 'DEC',
        'arrete' => 'ARR',
        'loi' => 'LOI',
        'ordonnance' => 'ORD',
    ];

    /** Codes de type que ce croisement a le droit de contredire. */
    private const TYPES_CROISABLES = ['DEC', 'ARR', 'LOI', 'ORD', 'CONST'];

    private const MOIS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    /**
     * Extrait le numéro du titre officiel, sans jamais deviner.
     *
     * @return array{
     *     numero: ?string,
     *     brut: ?string,
     *     confiance: ?string,
     *     motif_rejet: ?string
     * }
     */
    public function extraire(?string $titreOfficiel): array
    {
        $titre = trim((string) $titreOfficiel);

        if ($titre === '') {
            return $this->rejet('titre_vide');
        }

        // Un en-tête d'acte du Journal officiel est toujours capitalisé. Un
        // intitulé FLUX qui commence par une minuscule est un fragment de
        // phrase promu en document par le découpage (12 cas sur 12 au tirage
        // du 16/08/2026), pas un acte : lui donner une identité de citation
        // graverait ce défaut dans une URL. Motif distinct, parce que la
        // remédiation est une fusion de documents, pas une saisie de numéro.
        if (! preg_match('/^\p{Lu}/u', $titre)) {
            return $this->rejet('titre_non_capitalise');
        }

        if (! preg_match(self::DESIGNATION_EN_TETE, $titre, $correspondance)) {
            return $this->rejet('numero_absent');
        }

        // La désignation, privée de son premier mot : « Décret » ne doit pas
        // se disqualifier lui-même, mais « … portant application du décret »
        // doit disqualifier ce qui suit.
        $apresLePremierMot = (string) preg_replace('/^\p{L}+/u', '', trim($correspondance[1]));

        if (preg_match(self::DESIGNATION_CITANT_UN_AUTRE_ACTE, $apresLePremierMot)) {
            return $this->rejet('numero_d_un_autre_acte');
        }

        $suite = mb_substr($titre, mb_strlen($correspondance[0]));

        // Le numéro s'arrête au premier mot de liaison, à la première virgule
        // ou point-virgule, ou à la fin du titre.
        $brut = (string) preg_split(
            '/(?:\s+(?:'.self::FIN_DU_NUMERO.')(?=\s|$))|[,;]/iu',
            $suite,
            2,
        )[0];

        $numero = $this->normaliser($brut);

        if ($numero === null) {
            return $this->rejet('numero_illisible', $brut);
        }

        if (! $this->estConforme($numero)) {
            return $this->rejet('forme_non_conforme', $numero);
        }

        return [
            'numero' => $numero,
            'brut' => trim($brut),
            // Chiffres et tirets : la forme que le JO imprime pour l'écrasante
            // majorité des actes, et qu'aucune lecture OCR ne rend ambiguë.
            // Dès qu'une lettre ou un code de service apparaît, un humain
            // regarde : c'est là que l'OCR confond O et 0, I et 1.
            'confiance' => preg_match(self::FORME_CANONIQUE, $numero) ? 'haute' : 'a_verifier',
            'motif_rejet' => null,
        ];
    }

    /**
     * Type d'acte annoncé par le mot de tête du titre (`DEC`, `ARR`, `LOI`,
     * `ORD`, `CONST`), ou `null` si le titre n'annonce rien d'assez sûr.
     */
    public function typeAnnonceParLeTitre(?string $titreOfficiel): ?string
    {
        $titre = trim((string) $titreOfficiel);

        if (! preg_match('/^(\p{L}+)/u', $titre, $correspondance)) {
            return null;
        }

        $mot = mb_strtolower(Str::ascii($correspondance[1]));

        if ($mot === 'loi' && preg_match('/^loi\s+constitutionnelle/iu', $titre)) {
            return 'CONST';
        }

        return self::TYPE_PAR_MOT_DE_TETE[$mot] ?? null;
    }

    /**
     * Le type annoncé par le titre contredit-il le type enregistré ?
     *
     * Vrai seulement quand les deux sont sûrs : un titre sans mot de tête
     * reconnu, ou un document d'un type hors de la liste croisable (TEXTE,
     * CODE, AU…), ne peut pas être contredit.
     */
    public function typeContredit(?string $titreOfficiel, ?string $typeCode): bool
    {
        $annonce = $this->typeAnnonceParLeTitre($titreOfficiel);
        $enregistre = strtoupper((string) $typeCode);

        if ($annonce === null || ! in_array($enregistre, self::TYPES_CROISABLES, true)) {
            return false;
        }

        // Une loi constitutionnelle est enregistrée CONST ; « Loi n° … » sur un
        // document CONST reste cohérent (le titre abrège parfois).
        if ($annonce === 'LOI' && $enregistre === 'CONST') {
            return false;
        }

        return $annonce !== $enregistre;
    }

    /**
     * Date « du 20 juin 2025 » lue dans le titre, en ISO (`2025-06-20`), ou
     * `null`. Sert au croisement avec `date_signature` : quand les deux
     * existent et diffèrent, le titre parle probablement d'un autre acte, ou
     * l'une des deux dates est fausse — dans les deux cas un humain regarde.
     */
    public function dateAnnonceeParLeTitre(?string $titreOfficiel): ?string
    {
        $titre = (string) $titreOfficiel;

        if (! preg_match('/\b(?:du|le|en\s+date\s+du)\s+(1er|\d{1,2})\s+(\p{L}+)\s+(\d{4})\b/iu', $titre, $m)) {
            return null;
        }

        $mois = self::MOIS[mb_strtolower(Str::ascii($m[2]))] ?? null;

        if ($mois === null) {
            return null;
        }

        $jour = $m[1] === '1er' || mb_strtolower($m[1]) === '1er' ? 1 : (int) $m[1];

        if ($jour < 1 || $jour > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $m[3], $mois, $jour);
    }

    /**
     * Ramène un numéro à la forme stockée : telle qu'imprimée au Journal
     * officiel, sans « n° », sans espaces, sans points.
     *
     * Les espaces partent parce que le JO les met où il veut (« 46 - 2014 »,
     * « 4107/CAB 3 ») sans qu'ils portent le moindre sens : les garder ferait
     * de `46-2014` et `46 - 2014` deux citations différentes, donc deux URL
     * différentes pour le même acte. Les tirets Unicode (demi-cadratin de
     * « 2025–336 », vu en production) sont rabattus sur le tiret ASCII pour la
     * même raison.
     *
     * La CASSE, elle, n'est pas touchée : « 80-550/ETR-SGDAAPDP » est un code
     * de service imprimé tel quel, et rabattre sa casse serait réécrire la
     * source. Les comparaisons de citation se font donc sans tenir compte de
     * la casse (cf. `DoublonCitation`), plutôt que de normaliser à l'écriture.
     */
    public function normaliser(?string $brut): ?string
    {
        $numero = trim((string) $brut);

        if ($numero === '') {
            return null;
        }

        // « N° », « n º », « no », « numéro » collés au numéro quand la valeur
        // vient d'une saisie manuelle plutôt que de l'extraction.
        $numero = (string) preg_replace('/^n(?:[°ºo]|um[ée]ro)\s*/iu', '', $numero);

        // Tout tiret Unicode → tiret ASCII.
        $numero = (string) preg_replace('/\p{Pd}/u', '-', $numero);

        // Un point ENTRE deux codes est un séparateur, comme la barre :
        // « 80-493/MTJ.DGTFP.DFP/21021 » et « 80-493/MTJ/DGTFP/DFP/21021 »
        // désignent le même décret (deux lignes vues en production le 19/09),
        // et ne doivent donner qu'une citation — sinon le doublon échappe au
        // contrôle. Les points restants (fin de titre, abréviation) tombent.
        $numero = (string) preg_replace('/(?<=[\p{L}\d])\.(?=[\p{L}\d])/u', '/', $numero);

        // Espaces et points : sans valeur distinctive dans une référence.
        $numero = (string) preg_replace('/[\s.]+/u', '', $numero);

        // Ponctuation de fin de titre restée accrochée au numéro.
        $numero = trim($numero, '-/,;:');

        return $numero === '' ? null : $numero;
    }

    /**
     * La valeur a-t-elle la forme d'un numéro d'acte ?
     *
     * Volontairement STRICTE : mieux vaut signaler cent cas à un relecteur que
     * d'écrire un seul faux numéro, qui deviendrait une URL canonique fausse.
     */
    public function estConforme(?string $numero): bool
    {
        $numero = (string) $numero;

        if ($numero === '' || mb_strlen($numero) > self::LONGUEUR_MAX) {
            return false;
        }

        return (bool) preg_match(self::FORME_NORMALISEE, $numero);
    }

    /**
     * @return array{numero: ?string, brut: ?string, confiance: ?string, motif_rejet: ?string}
     */
    private function rejet(string $motif, ?string $brut = null): array
    {
        return [
            'numero' => null,
            'brut' => $brut === null ? null : trim($brut),
            'confiance' => null,
            'motif_rejet' => $motif,
        ];
    }
}
