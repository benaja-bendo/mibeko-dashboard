<?php

namespace App\Services\Curation;

use App\Services\LatexArtifactCleaner;

/**
 * Dérive du CORPS d'un acte un libellé descriptif lisible, pour les documents
 * dont l'intitulé officiel se réduit au type, au numéro et à la date.
 *
 * Le Journal officiel publie certaines décisions en « actes en abrégé » : le
 * sommaire n'annonce que « Nomination. », l'en-tête n'imprime aucun objet, et
 * l'intitulé du texte est littéralement « Décret n° 2025-240 du 20 juin 2025. ».
 * Vérifié le 16/08/2026 contre les markdowns MinerU (tirage de 10, 9 fidèles) :
 * ces intitulés sont EXACTS, il n'y a rien à réparer. Ce que cette classe
 * produit n'est donc PAS un titre — c'est une paraphrase de ce que l'acte fait,
 * destinée aux listes, à la recherche et au fil d'Ariane, à côté du titre
 * officiel et jamais à sa place.
 *
 * Rien n'est inventé : le libellé est toujours découpé dans le texte de
 * l'article, jamais reformulé. La seule liberté prise est de nommer la nature
 * de l'acte (« Nomination », « Décoration »…) avec le vocabulaire du sommaire
 * du JO lui-même, et de normaliser une casse tout en capitales.
 *
 * Aucune méthode n'écrit : la sortie est une PROPOSITION, relue par un humain
 * (cf. `mibeko:proposer-libelles`, puis `mibeko:appliquer-libelles`).
 */
class LibelleDescriptifExtractor
{
    /** Au-delà, le libellé cesse d'être lisible dans une liste — on tronque et on doute. */
    public const LONGUEUR_MAX = 160;

    /** En deçà, il ne reste rien d'exploitable (« Arrête : », un numéro de page seul…). */
    private const LONGUEUR_MIN = 12;

    /**
     * Connecteurs par lesquels un intitulé officiel énonce son objet.
     *
     * Quand le CORPS commence par l'un d'eux, le JO avait bien imprimé un objet
     * — c'est le découpage MinerU qui l'a détaché du titre. Le libellé est
     * alors la meilleure copie possible de cet objet, et le document mérite en
     * plus d'être signalé : son `titre_officiel` est probablement tronqué, ce
     * qui relève d'un autre chantier (`mibeko:proposer-titres`).
     */
    private const CONNECTEURS_OBJET = 'portant|fixant|modifiant|compl[ée]tant|relatif|relative|approuvant'
        .'|autorisant|cr[ée]ant|instituant|abrogeant|d[ée]terminant|d[ée]finissant|organisant'
        .'|prorogeant|rendant|convoquant|nommant|d[ée]clarant|allouant|accordant|attribuant'
        .'|retirant|suspendant|renouvelant|transf[ée]rant|[ée]rigeant|classant|homologuant'
        .'|r[ée]glementant|interdisant|sur\s+(?:la|le|les)';

    /**
     * Natures d'acte, avec le motif qui les reconnaît dans le corps.
     *
     * Vocabulaire calqué sur le sommaire du JO (« Nomination. »), et fréquences
     * mesurées le 16/08/2026 sur les 294 actes en abrégé de la base de
     * développement : nomination 125, décoration 9, modification 6,
     * naturalisation 4, inscription 4, intégration 3. Les natures plus rares
     * (promotion, versement, retraite…) sont conservées : elles sont attestées
     * dans le corpus mais masquées, dans cette mesure, par un motif antérieur.
     *
     * L'ORDRE COMPTE : le premier motif qui accroche gagne.
     *
     * @var array<string, string>
     */
    private const NATURES = [
        'nomination' => '/\b(?:est\s+nommée?|sont\s+nommée?s)\b/iu',
        'decoration' => '/\b(?:sont\s+décorée?s|est\s+décorée?)\b/iu',
        'naturalisation' => '/\b(?:est|sont)\s+naturalisée?s?\b/iu',
        'inscription' => '/\binscrits?\s+au\s+tableau\s+d[\'’]avancement\b/iu',
        'promotion' => '/\b(?:est\s+promue?|sont\s+promue?s)\b/iu',
        'retraite' => '/\b(?:droits\s+à\s+la\s+retraite|admise?s?\s+à\s+faire\s+valoir)\b/iu',
        'versement' => '/\b(?:est\s+versée?|sont\s+versée?s)\b/iu',
        'avancement' => '/\b(?:est\s+avancée?|sont\s+avancée?s)\b/iu',
        'titularisation' => '/\b(?:est|sont)\s+titularisée?s?\b/iu',
        'integration' => '/\b(?:est|sont)\s+intégrée?s?\b/iu',
        'agrement' => '/\b(?:est|sont)\s+agréée?s?\b/iu',
        'autorisation' => '/\b(?:est|sont)\s+autorisée?s?\b/iu',
        'approbation' => '/\b(?:est|sont)\s+approuvée?s?\b/iu',
        'creation' => '/\b(?:il\s+est\s+créé|(?:est|sont)\s+créée?s?)\b/iu',
        'modification' => '/\b(?:est|sont)\s+(?:modifiée?s?|rectifiée?s?)\b/iu',
    ];

    /**
     * Libellé affiché pour chaque nature — le mot du sommaire du JO.
     *
     * @var array<string, string>
     */
    private const INTITULES_NATURE = [
        'nomination' => 'Nomination',
        'decoration' => 'Décoration',
        'naturalisation' => 'Naturalisation',
        'inscription' => 'Inscription au tableau d\'avancement',
        'promotion' => 'Promotion',
        'retraite' => 'Admission à la retraite',
        'versement' => 'Versement',
        'avancement' => 'Avancement',
        'titularisation' => 'Titularisation',
        'integration' => 'Intégration',
        'agrement' => 'Agrément',
        'autorisation' => 'Autorisation',
        'approbation' => 'Approbation',
        'creation' => 'Création',
        'modification' => 'Modification',
    ];

    public function __construct(private readonly LatexArtifactCleaner $latex = new LatexArtifactCleaner) {}

    /**
     * L'intitulé se réduit-il au type, au numéro et à la date ?
     *
     * Volontairement STRICT, et pas seulement « se termine par une date » comme
     * le détecteur `I1_acte_en_abrege` : mesuré le 16/08/2026 sur la base de
     * développement, la condition du détecteur ramène 1 472 documents, dont
     * ~1 100 sont en réalité des actes dont le CORPS a été avalé dans
     * l'intitulé (famille `P1_corps_dans_titre`) et qui, par hasard, finissent
     * sur une date (« … pour compter du 2 avril 2007. »). Leur coller un
     * libellé descriptif serait aggraver un défaut au lieu de le compenser.
     * Ici, l'intitulé doit être ENTIÈREMENT consommé par le motif.
     *
     * L'initiale majuscule est exigée : une initiale minuscule signe un faux
     * acte né d'une ligne de sommaire coupée (constat du 16/08/2026, 12 cas
     * sur 12), pas un acte en abrégé.
     */
    public function estActeEnAbrege(?string $titreOfficiel): bool
    {
        $titre = trim((string) $titreOfficiel);

        if ($titre === '' || ! preg_match('/^\p{Lu}/u', $titre)) {
            return false;
        }

        // Le numéro d'acte n'est pas un entier : le JO écrit « 4107/CAB 3 »,
        // « 14 bis/59 », « 46 - 2014 », « 2025–336 » (tiret demi-cadratin).
        // Un motif qui n'accepterait que `\d[\w./-]*` écarterait ces actes
        // comme s'ils portaient un objet — 5 faux négatifs sur 178 mesurés en
        // production le 17/08/2026. D'où `\p{Pd}` (tout tiret Unicode) et
        // jusqu'à DEUX segments espacés après le numéro : assez pour « bis/59 »
        // ou « - 2014 », trop peu pour avaler un objet de titre.
        $segmentNumero = '[\w.\/\p{Pd}]';

        return (bool) preg_match(
            '/^\p{L}[\p{L}\'’ -]{0,40}n[°ºo]\s*\d'.$segmentNumero.'*'
            .'(?:\s+[\dA-Za-z\p{Pd}]'.$segmentNumero.'*){0,2}'
            .'\s+(?:du|le)\s+'
            .'\d{1,2}(?:er)?\s+\p{L}+\s+(?:1[6-9]\d{2}|20\d{2})\s*[.,]?\s*$/iu',
            $titre,
        );
    }

    /**
     * Propose un libellé à partir du texte du premier article.
     *
     * @return array{
     *     libelle: ?string,
     *     nature: ?string,
     *     confiance: ?string,
     *     titre_probablement_tronque: bool,
     *     extrait_source: ?string,
     *     motif_rejet: ?string
     * }
     */
    public function proposer(?string $premierArticle): array
    {
        $texte = $this->nettoyer((string) $premierArticle);

        if (mb_strlen($texte) < self::LONGUEUR_MIN) {
            return $this->rejet('corps_vide');
        }

        if ($this->estDuBruit($texte)) {
            return $this->rejet('corps_illisible');
        }

        $objet = $this->objetDetache($texte);

        if ($objet !== null) {
            return $this->retenue(
                libelle: $objet['libelle'],
                nature: 'objet_detache',
                confiance: $objet['confiance'],
                extrait: $objet['extrait'],
                titreTronque: true,
            );
        }

        foreach (self::NATURES as $nature => $motif) {
            if (! preg_match($motif, $texte, $correspondance, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $complement = $this->complement($nature, $texte, $correspondance[0]);
            $intitule = self::INTITULES_NATURE[$nature];

            return $this->retenue(
                libelle: $complement === null ? $intitule : "{$intitule} : {$complement}",
                nature: $nature,
                // Sans complément, le libellé ne dit que la catégorie de l'acte
                // (« Décoration ») : utile pour trier, insuffisant pour
                // distinguer deux actes du même jour. Un humain doit trancher.
                confiance: $complement === null ? 'a_verifier' : 'haute',
                extrait: $this->premierePhrase($texte),
            );
        }

        // Aucune nature reconnue : on retombe sur la première phrase brute.
        // Elle décrit presque toujours l'acte, mais dans les mots du greffier
        // (« Mme X, née Y, domiciliée à Z, est… ») — à raccourcir à la main.
        $phrase = $this->premierePhrase($texte);

        if ($phrase === null || mb_strlen($phrase) < self::LONGUEUR_MIN) {
            return $this->rejet('aucune_phrase_exploitable');
        }

        return $this->retenue(
            libelle: $this->tronquer($this->normaliserLaCasse($phrase)),
            nature: null,
            confiance: 'a_verifier',
            extrait: $phrase,
        );
    }

    /**
     * Remet le texte OCR dans un état lisible, sans jamais rien réinterpréter.
     */
    private function nettoyer(string $texte): string
    {
        $texte = $this->latex->nettoyer($texte);

        // Ligatures typographiques que MinerU rend telles quelles, en glissant
        // souvent une espace derrière (« ﬁ xant » = « fixant », « planiﬁ cation »
        // = « planification »). L'espace parasite se retire AVEC la ligature :
        // hors ligature, on n'y touche pas.
        $texte = strtr($texte, [
            'ﬁ ' => 'fi', 'ﬂ ' => 'fl', 'ﬀ ' => 'ff', 'ﬃ ' => 'ffi', 'ﬄ ' => 'ffl',
            'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬀ' => 'ff', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl',
        ]);

        // Marqueurs de citabilité par page, propres à Mibeko.
        $texte = (string) preg_replace('/\[\[MIBEKO_PAGE:\d+\]\]/u', ' ', $texte);

        // Ours du JO recollé au texte par l'OCR, avec sa pagination et sa date
        // de parution (« … 1799 Du Jeudi 11 décembre 2025 portant approbation… »).
        $texte = (string) preg_replace(
            '/\s*\d{0,4}\s*Journal\s+officiel\s+de\s+la\s+R[ée]publique\s+du\s+Congo\s*\d{0,4}\s*'
            .'(?:N[°ºo]\s*[\d\s-]{1,12})?\s*'
            .'(?:Du\s+\p{L}+\s+\d{1,2}(?:er)?\s+\p{L}+\s+\d{4})?/iu',
            ' ',
            $texte,
        );

        // Césures de fin de ligne jamais recollées (« comi- té », « con- tractuelle »).
        $texte = (string) preg_replace('/(\p{Ll})-\s+(\p{Ll})/u', '$1$2', $texte);

        return trim((string) preg_replace('/\s+/u', ' ', $texte));
    }

    /**
     * Le texte n'est-il que du bruit de mise en page ?
     */
    private function estDuBruit(string $texte): bool
    {
        // Un reste d'ours ou de pagination après nettoyage : le premier article
        // n'est pas le dispositif de l'acte mais un fragment de page.
        if (preg_match('/^\s*(?:\d+\s*)?(?:Du\s+\p{L}+\s+\d{1,2})/u', $texte)) {
            return true;
        }

        // Aucune lettre : un bloc de chiffres, de tirets ou de ponctuation.
        return ! preg_match('/\p{L}/u', $texte);
    }

    /**
     * L'objet que le JO avait imprimé, détaché du titre par le découpage.
     *
     * @return array{libelle: string, confiance: string, extrait: string}|null
     */
    private function objetDetache(string $texte): ?array
    {
        if (! preg_match('/^(?:'.self::CONNECTEURS_OBJET.')\b/iu', $texte)) {
            return null;
        }

        $objet = $this->couperAuxVisas($texte);

        if ($objet === null || mb_strlen($objet) < self::LONGUEUR_MIN) {
            return null;
        }

        $tropLong = mb_strlen($objet) > self::LONGUEUR_MAX;
        $enCapitales = $this->estToutEnCapitales($objet);

        return [
            'libelle' => $this->tronquer($this->normaliserLaCasse($objet)),
            // Une coupure nette et courte se relit d'un coup d'œil ; une
            // troncature, ou une casse qu'on a dû rabattre (textes de 1959
            // imprimés tout en capitales, sans accents), demandent un contrôle.
            'confiance' => $tropLong || $enCapitales ? 'a_verifier' : 'haute',
            'extrait' => $objet,
        ];
    }

    /**
     * Coupe le texte au premier repère de visa ou de formule d'autorité.
     *
     * Même frontière que `mibeko:proposer-titres` : l'objet d'un acte s'arrête
     * là où commencent « Le Président de la République, », « Vu la
     * Constitution ; » ou « L'Assemblée nationale a délibéré ».
     */
    private function couperAuxVisas(string $texte): ?string
    {
        $reperes = [
            // Les textes de 1958-59 sont signés par des autorités coloniales
            // que la liste ministérielle ne couvrait pas (« LE CHEF DU
            // TERRITOIRE DU MOYEN-CONGO »), et impriment « VU » en capitales :
            // sans ces deux ajouts, tout le bloc des visas passait dans le
            // libellé (1 cas sur 176 mesuré en production le 17/08/2026).
            '/(?:^|\s)(?:LE|LA|Le|La)\s+(?:Président|président|Premier|premier|ministre|Ministre|MINISTRE'
            .'|PRESIDENT|PRÉSIDENT|garde|Garde|Conseil|Cour|maire|Maire|directeur|Directeur|préfet|Préfet'
            .'|CHEF|Chef|chef|GOUVERNEUR|Gouverneur|gouverneur|haut-commissaire|HAUT-COMMISSAIRE)/u',
            // Même formule, mais soudée par l'OCR des textes de 1958-59 :
            // « LePremier Ministre », « LcPremier Ministre », « LePremierMinistre ».
            // Sans ce repère, tout le bloc des visas passait dans le libellé.
            '/\bL[eac](?:Premier|Président|PRESIDENT|PRÉSIDENT|Ministre|MINISTRE)/u',
            '/\b(?:Vu|VU)\s+(?:la|l[\'’]|le|les|LA|L[\'’]|LE|LES)\b/u',
            '/\bConsidérant\b/iu',
            '/L[\'’]Assembl[ée]/iu',
            '/\bSur\s+la\s+proposition\b/iu',
            '/\bDécrète\s*:/iu',
            '/\bArrête\s*:/iu',
        ];

        $position = null;
        foreach ($reperes as $repere) {
            if (preg_match($repere, $texte, $correspondances, PREG_OFFSET_CAPTURE)) {
                $offset = $correspondances[0][1];
                if ($position === null || $offset < $position) {
                    $position = $offset;
                }
            }
        }

        $objet = $position !== null ? mb_strcut($texte, 0, $position) : $texte;
        $objet = rtrim(trim($objet), " \t,;:-–—");

        return $objet === '' ? null : $objet;
    }

    /**
     * Le complément qui donne son sens au libellé, quand il est extractible
     * sans deviner.
     *
     * Deux natures seulement s'y prêtent de façon déterministe : la nomination,
     * où la fonction suit immédiatement le verbe, et la modification, où le
     * texte modifié est nommé par sa référence. Ailleurs, le complément est
     * noyé dans l'état civil de l'intéressé — mieux vaut n'en proposer aucun
     * que d'en fabriquer un.
     *
     * @param  array{0: string, 1: int}  $correspondance  Motif trouvé et son offset en octets.
     */
    private function complement(string $nature, string $texte, array $correspondance): ?string
    {
        if ($nature === 'modification') {
            if (preg_match(
                '/\b((?:décret|arrêté|loi|ordonnance|décision|arrete)\s+n[°ºo]\s*[\d\w-]+'
                .'(?:\s+du\s+\d{1,2}(?:er)?\s+\p{L}+\s+\d{4})?)/iu',
                $texte,
                $reference,
            )) {
                return 'du '.mb_strtolower(trim($reference[1]));
            }

            return null;
        }

        if ($nature !== 'nomination') {
            return null;
        }

        $suite = trim(mb_strcut($texte, $correspondance[1] + strlen($correspondance[0])));

        // La fonction s'arrête à la fin de la phrase — le reste de l'acte
        // parle d'indemnités et d'abrogations, pas de l'objet.
        if (preg_match('/^(.{5,}?)(?:\.\s|\.$|;|\z)/us', $suite, $phrase)) {
            $suite = $phrase[1];
        }

        // Filet pour les points que l'OCR a perdus : la phrase suivante d'un
        // acte de nomination est toujours l'une de ces formules de queue, et
        // elle se retrouve sinon collée à la fonction (« … du genie
        // L'interessé percevra à ce titre… », constaté le 16/08/2026).
        $suite = (string) preg_split(
            '/\s+(?:L[\'’](?:int[ée]ress[ée]e?s?|assembl[ée]e)|Les?\s+int[ée]ress[ée]e?s?|Le\s+pr[ée]sent'
            .'|Conform[ée]ment\s+aux|Il\s+percevra|Elle\s+percevra|M(?:me|lle|\.)\s)/u',
            $suite,
            2,
        )[0];

        $suite = trim($suite, " \t.,;:");

        // « à titre définitif », « pour compter du… » : une queue de phrase
        // sans fonction nommée ne fait pas un libellé.
        if (mb_strlen($suite) < 4 || mb_strlen($suite) > self::LONGUEUR_MAX) {
            return null;
        }

        return $this->normaliserLaCasse($suite, minusculeInitiale: true);
    }

    /**
     * La première phrase du texte, comme repli et comme pièce à conviction
     * montrée au relecteur.
     */
    private function premierePhrase(string $texte): ?string
    {
        // Les visas et la formule d'autorité ne font jamais partie de l'objet,
        // y compris ici : sans cette coupure, le repli recopiait « … du
        // règlement intérieur du Parlement La Cour constitutionnelle, Saisie
        // par lettre n° 0280/AN/P-CAB… » (constaté le 16/08/2026).
        //
        // Une coupure qui ne laisse RIEN veut dire que l'article commence
        // directement par la formule d'autorité — le premier article n'est pas
        // le dispositif, c'est l'en-tête de l'acte, et il n'y a aucun objet à
        // en tirer. On le dit, plutôt que de recopier le bloc des visas dans
        // le libellé (constaté en production le 17/08/2026 sur l'arrêté
        // n° 4107/CAB 3 : « LE CHEF DU TERRITOIRE DU MOYEN-CONGO Officier de
        // la Légion d'Honneur, VU la Constitution… »).
        $texte = $this->couperAuxVisas($texte);

        if ($texte === null) {
            return null;
        }

        if (preg_match('/^(.{10,}?)(?:\.\s+\p{Lu}|\.\s*$)/us', $texte, $correspondances)) {
            return trim($correspondances[1]);
        }

        return $texte === '' ? null : $texte;
    }

    /**
     * Le texte est-il imprimé tout en capitales ?
     *
     * Mesuré en PROPORTION, pas en tout-ou-rien : l'OCR des textes de 1958-59
     * laisse presque toujours quelques minuscules parasites au milieu des
     * capitales (« DE SCHlichtING »), et un test strict les déclarerait alors
     * en casse normale.
     */
    private function estToutEnCapitales(string $texte): bool
    {
        $capitales = preg_match_all('/\p{Lu}/u', $texte);
        $minuscules = preg_match_all('/\p{Ll}/u', $texte);

        return $capitales >= 8 && $capitales > ($capitales + $minuscules) * 0.8;
    }

    /**
     * Met une majuscule initiale — seule retouche de forme autorisée.
     *
     * Une casse tout en capitales n'est PAS rabattue en minuscules : cela
     * détruirait les noms propres (« DE SCHLICHTING » → « de schlichting ») et
     * les accents perdus par l'OCR de 1958 ne reviendraient pas pour autant.
     * Ces libellés partent en `a_verifier`, à un humain de trancher.
     */
    private function normaliserLaCasse(string $texte, bool $minusculeInitiale = false): string
    {
        if ($minusculeInitiale) {
            return $texte;
        }

        return mb_strtoupper(mb_substr($texte, 0, 1)).mb_substr($texte, 1);
    }

    private function tronquer(string $texte): string
    {
        if (mb_strlen($texte) <= self::LONGUEUR_MAX) {
            return $texte;
        }

        // Coupe sur un mot entier, jamais au milieu.
        $coupe = mb_substr($texte, 0, self::LONGUEUR_MAX - 1);
        $dernierEspace = mb_strrpos($coupe, ' ');

        if ($dernierEspace !== false && $dernierEspace > self::LONGUEUR_MAX / 2) {
            $coupe = mb_substr($coupe, 0, $dernierEspace);
        }

        return rtrim($coupe, " \t,;:-–—").'…';
    }

    /**
     * @return array{libelle: ?string, nature: ?string, confiance: ?string, titre_probablement_tronque: bool, extrait_source: ?string, motif_rejet: ?string}
     */
    private function rejet(string $motif): array
    {
        return [
            'libelle' => null,
            'nature' => null,
            'confiance' => null,
            'titre_probablement_tronque' => false,
            'extrait_source' => null,
            'motif_rejet' => $motif,
        ];
    }

    /**
     * @return array{libelle: ?string, nature: ?string, confiance: ?string, titre_probablement_tronque: bool, extrait_source: ?string, motif_rejet: ?string}
     */
    private function retenue(
        string $libelle,
        ?string $nature,
        string $confiance,
        ?string $extrait,
        bool $titreTronque = false,
    ): array {
        return [
            'libelle' => $libelle,
            'nature' => $nature,
            'confiance' => $confiance,
            'titre_probablement_tronque' => $titreTronque,
            'extrait_source' => $extrait,
            'motif_rejet' => null,
        ];
    }
}
