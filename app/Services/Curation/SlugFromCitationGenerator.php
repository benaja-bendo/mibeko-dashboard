<?php

namespace App\Services\Curation;

use App\Models\LegalDocument;
use Illuminate\Support\Str;

/**
 * Dérive le slug canonique d'un texte de sa CITATION — type, numéro, date de
 * signature — plutôt que de son titre officiel (décision du 19/09/2026,
 * `docs/decisions.md`, schéma d'URL du fonds).
 *
 * Le titre est un texte libre que la curation corrige ; la citation ne change
 * jamais. C'est ce qui rend le slug stable, et c'est pourquoi elle en devient
 * la source — mais seulement pour les types où le mot qui la désigne est sans
 * ambiguïté (`decret-n-2025-240-du-20-juin-2025` se lit comme une vraie
 * citation juridique). Pour tout le reste — codes, constitutions, actes
 * uniformes OHADA, textes sans référence — cette classe renvoie `null` :
 * leur identité relève d'une décision de curation, pas d'une règle
 * mécanique (dashboard#156, § « les cas qui n'ont pas de citation »).
 *
 * Ne fait AUCUNE écriture : `mibeko:regenerer-slugs` en fait la seule
 * consommatrice, pour un texte publié à la fois, après avoir vérifié
 * qu'aucune collision de citation ne rend deux candidats identiques.
 */
class SlugFromCitationGenerator
{
    /**
     * Mot français qui désigne le type dans une citation lisible. Seuls les
     * types listés ici reçoivent un slug généré ; les autres (`TEXTE`, `AU`,
     * `CODE`, `JO`, `""`…) gardent leur slug actuel — leur identité se
     * décide par curation (nom du code, objet de l'acte uniforme), jamais
     * par un mot de type générique qui n'existerait pas juridiquement.
     */
    private const MOT_PAR_TYPE = [
        'DEC' => 'décret',
        'ARR' => 'arrêté',
        'LOI' => 'loi',
        'ORD' => 'ordonnance',
        // Une constitution non numérotée s'identifie par son année, pas par
        // un numéro d'acte (elle n'en a pas au sens de ce champ) : seule une
        // LOI CONSTITUTIONNELLE numérotée entre dans la citation.
        'CONST' => 'loi constitutionnelle',
    ];

    private const MOIS = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    /**
     * Slug candidat pour ce document, ou `null` si son type ou l'état de ses
     * champs structurés ne permet pas d'en dériver un de sa citation.
     *
     * Ne compare PAS au slug actuel — c'est à l'appelant de décider si le
     * candidat vaut la peine d'être écrit (`mibeko:regenerer-slugs` saute
     * les documents déjà conformes).
     */
    public function candidat(LegalDocument $document): ?string
    {
        $citation = $this->citationLisible($document);

        if ($citation === null) {
            return null;
        }

        $slug = trim(Str::slug($citation), '-');

        return $slug === '' ? null : $slug;
    }

    /**
     * Citation en français courant, forme d'une vraie référence juridique
     * (« décret n° 2025-240 du 20 juin 2025 »). `Str::slug()` s'occupe de la
     * translittération (accents, casse, ponctuation) — écrire la citation
     * telle qu'elle se lit est plus sûr que composer le slug caractère par
     * caractère.
     */
    private function citationLisible(LegalDocument $document): ?string
    {
        $mot = self::MOT_PAR_TYPE[strtoupper((string) $document->type_code)] ?? null;
        $numero = trim((string) $document->numero_acte);

        if ($mot === null || $numero === '') {
            return null;
        }

        $date = $this->dateLisible($document->date_signature);

        if ($date === null) {
            return null;
        }

        return "{$mot} n° {$numero} du {$date}";
    }

    /**
     * `date_signature` (une `Carbon\Carbon`, cast par le modèle) en
     * « 20 juin 2025 » / « 1er août 2019 ». `null` si la date est absente —
     * une citation sans date n'en est pas une, `estActeEnAbrege()` et
     * `NumeroActeExtractor` exigent déjà les deux ensemble.
     */
    private function dateLisible(mixed $dateSignature): ?string
    {
        if ($dateSignature === null) {
            return null;
        }

        $jour = (int) $dateSignature->format('j');
        $moisTexte = self::MOIS[(int) $dateSignature->format('n')] ?? null;

        if ($moisTexte === null) {
            return null;
        }

        $jourTexte = $jour === 1 ? '1er' : (string) $jour;

        return "{$jourTexte} {$moisTexte} {$dateSignature->format('Y')}";
    }
}
