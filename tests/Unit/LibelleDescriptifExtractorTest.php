<?php

use App\Services\Curation\LibelleDescriptifExtractor;

/**
 * `LibelleDescriptifExtractor` : dérive du corps de l'acte un libellé lisible,
 * sans jamais toucher ni prétendre remplacer l'intitulé officiel.
 *
 * Tous les intitulés et tous les corps d'article ci-dessous sont RÉELLEMENT
 * relevés dans le corpus de développement le 16/08/2026 (`legal_documents` et
 * `article_versions.contenu_texte`), pas des cas inventés — y compris les
 * accidents d'OCR (« ﬁ xant », « LePremier Ministre », « comi- té »), qui sont
 * précisément ce que l'extracteur doit encaisser.
 */
beforeEach(function () {
    $this->extracteur = new LibelleDescriptifExtractor;
});

dataset('actes en abrégé', [
    'décret avec point final' => ['Décret n° 2025-240 du 20 juin 2025.'],
    'décret sans point final' => ['Décret n° 2025-387 du 16 septembre 2025'],
    'arrêté à numéro long' => ['Arrêté n° 14532 du 14 novembre 2023'],
    'avis à numéro composé' => ['Avis n° 001-ACC-SVC/25 du 13 juin 2025.'],
    'double espace après n°' => ['Décret n°  2025-340 du 7 août 2025.'],
    'texte ancien en capitales' => ['LOI N° 12/59. DU 17 FÉVRIER 1959'],
    // Les quatre formes de numéro relevées en PRODUCTION le 17/08/2026, que
    // le premier motif écartait à tort (5 faux négatifs sur 178).
    'numéro à service et rang' => ['ARRETE N° 4107/CAB 3 DU 28 NOVEMBRE 1958'],
    'numéro bis' => ['LOI N° 14 bis/59 DU 17 FEVRIER 1959'],
    'numéro à tiret espacé' => ['Loi n° 46 - 2014 du 3 novembre 2014'],
    'numéro à tiret demi-cadratin' => ['Décret n° 2025–336 du 30 juillet 2025.'],
]);

dataset('intitulés hors périmètre', [
    // Le défaut P1 du détecteur : le corps de l'acte a été avalé dans
    // l'intitulé, qui finit par hasard sur une date. Mesuré le 16/08/2026,
    // ce cas représente ~1 100 des 1 472 documents que la condition SQL du
    // détecteur `I1_acte_en_abrege` ramène à elle seule.
    'corps avalé dans le titre' => [
        'Arrêté n° 2084 du 15 avril 2009. Mlle MAKAMBO (Anne Faustine), secrétaire '
        ."d'administration de 3e classe, 1er échelon, est promue à deux ans, au titre de "
        ."l'année 2006, au 2e échelon, indice 885 pour compter du 21 février 2006.",
    ],
    // L'initiale minuscule signe un faux acte né d'une ligne de sommaire
    // coupée (constat du 16/08/2026, 12 cas sur 12).
    'faux acte à initiale minuscule' => ['loi organique n° 57-2020 du 18 novembre 2020'],
    'intitulé qui énonce son objet' => ['Décret n° 2025-390 du 18 septembre 2025 portant attributions'],
    // Les deux garde-fous du motif élargi du 17/08/2026 : ces intitulés de
    // production finissent bien par une date, mais énoncent leur objet — les
    // segments espacés tolérés après le numéro ne doivent pas les avaler.
    'objet énoncé, se terminant par une seconde date' => [
        'Loi constitutionnelle n° 3 du 16 février 1959, suspendant provisoirement '
        ."l'application de l'article 2 de la loi constitutionnelle n° 1 du 28 novembre 1958",
    ],
    'annexe dont l\'objet précède le numéro' => [
        'Statuts du centre africain de recherche en intelligence artificielle, '
        .'annexés au décret n° 2025-279 du 2 juillet 2025',
    ],
    'intitulé sans numéro' => ['Constitution du 25 octobre 2015'],
    'intitulé vide' => [''],
]);

it('reconnaît un acte en abrégé', function (string $titre) {
    expect($this->extracteur->estActeEnAbrege($titre))->toBeTrue();
})->with('actes en abrégé');

it('écarte les intitulés qui ne sont pas des actes en abrégé', function (string $titre) {
    expect($this->extracteur->estActeEnAbrege($titre))->toBeFalse();
})->with('intitulés hors périmètre');

it('écarte un intitulé absent', function () {
    expect($this->extracteur->estActeEnAbrege(null))->toBeFalse();
});

it('nomme la fonction quand l\'acte est une nomination', function () {
    $resultat = $this->extracteur->proposer(
        'M. MILANDOU NSONGA (Médard) est nommé président du Conseil supérieur de la liberté '
        .'de communication. M. MILANDOU NSONGA (Médard) percevra les indemnités prévues par '
        .'les textes en vigueur.'
    );

    expect($resultat['libelle'])->toBe('Nomination : président du Conseil supérieur de la liberté de communication')
        ->and($resultat['nature'])->toBe('nomination')
        ->and($resultat['confiance'])->toBe('haute')
        ->and($resultat['titre_probablement_tronque'])->toBeFalse();
});

it('coupe la fonction sur la formule de queue quand l\'OCR a perdu le point', function () {
    // Relevé tel quel le 16/08/2026 : aucun point après « genie », la phrase
    // suivante se retrouvait collée à la fonction dans le libellé.
    $resultat = $this->extracteur->proposer(
        'M. NGATSE (Rock) est nommé chef de division administrative et financière de la '
        ."direction centrale du genie L'interessé percevra à ce titre les indemnités prévues."
    );

    expect($resultat['libelle'])
        ->toBe('Nomination : chef de division administrative et financière de la direction centrale du genie');
});

it('reprend l\'objet que le JO avait imprimé quand le découpage l\'a détaché du titre', function () {
    $resultat = $this->extracteur->proposer(
        "portant ratiﬁ cation de l'adhésion à l'amendement de la convention sur la protection "
        .'physique des matières nucléaires Le Président de la République, Vu la Constitution ;'
    );

    // La ligature « ﬁ  » et son espace parasite sont recollées, et la coupure
    // tombe avant la formule d'autorité.
    expect($resultat['libelle'])
        ->toBe("Portant ratification de l'adhésion à l'amendement de la convention sur la protection physique des matières nucléaires")
        ->and($resultat['nature'])->toBe('objet_detache')
        // Signal pour le relecteur : ici le JO avait bien imprimé un objet,
        // donc `titre_officiel` est probablement tronqué — autre chantier.
        ->and($resultat['titre_probablement_tronque'])->toBeTrue();
});

it('coupe avant la formule d\'autorité soudée par l\'OCR de 1958', function () {
    $resultat = $this->extracteur->proposer(
        'PORTANT FIXATION DES LIMITES DU DISTRICT DE BOKO-SONGHO LePremier Ministre de la '
        .'République du Congo, Vu la loi constitutionnelle'
    );

    expect($resultat['libelle'])->toBe('PORTANT FIXATION DES LIMITES DU DISTRICT DE BOKO-SONGHO')
        // Une casse tout en capitales n'est pas rabattue — cela détruirait les
        // noms propres — mais elle passe en relecture humaine.
        ->and($resultat['confiance'])->toBe('a_verifier');
});

it('écarte un article qui n\'est que la formule d\'autorité et ses visas', function () {
    // Relevé en production le 17/08/2026 sur l'arrêté n° 4107/CAB 3 : le
    // premier article n'est pas le dispositif, c'est l'en-tête de l'acte. Il
    // n'y a aucun objet à en tirer — le recopier produisait un libellé fait
    // du bloc des visas. Ni « LE CHEF DU TERRITOIRE » ni le « VU » en
    // capitales n'étaient reconnus comme repères de coupure.
    $resultat = $this->extracteur->proposer(
        "LE CHEF DU TERRITOIRE DU MOYEN-CONGO Officier de la Légion d'Honneur, "
        .'VU la Constitution, et notamment ses articles 76 et 77'
    );

    expect($resultat['libelle'])->toBeNull()
        ->and($resultat['motif_rejet'])->toBe('aucune_phrase_exploitable');
});

it('retire l\'ours du Journal officiel recollé au texte par l\'OCR', function () {
    $resultat = $this->extracteur->proposer(
        'Journal officiel de la République du Congo 1799 Du Jeudi 11 décembre 2025 portant '
        .'approbation des statuts de l\'agence nationale de l\'aviation civile Le Président '
        .'de la République, Vu la Constitution ;'
    );

    expect($resultat['libelle'])
        ->toBe("Portant approbation des statuts de l'agence nationale de l'aviation civile")
        ->and($resultat['nature'])->toBe('objet_detache');
});

it('recolle les césures OCR et les marqueurs de page', function () {
    $resultat = $this->extracteur->proposer(
        'Sont décorés, à titre normal, dans l\'ordre de la médaille d\'honneur du comi- té '
        .'national [[MIBEKO_PAGE:15]] des sports.'
    );

    expect($resultat['libelle'])->toBe('Décoration')
        ->and($resultat['nature'])->toBe('decoration')
        // Sans complément, le libellé ne dit que la catégorie : à relire.
        ->and($resultat['confiance'])->toBe('a_verifier')
        ->and($resultat['extrait_source'])->toContain('comité national');
});

it('nomme le texte modifié quand l\'acte est une modification', function () {
    $resultat = $this->extracteur->proposer(
        "L'article premier du décret n° 2025-176 du 13 mai 2025 est modifié, en ce qui "
        .'concerne les conseillères municipales du département de Brazzaville.'
    );

    expect($resultat['libelle'])->toBe('Modification : du décret n° 2025-176 du 13 mai 2025')
        ->and($resultat['nature'])->toBe('modification');
});

it('retombe sur la première phrase quand aucune nature n\'est reconnue', function () {
    $resultat = $this->extracteur->proposer(
        "En application des dispositions de l'article 10 du règlement, la séance est levée. "
        .'Le président signe.'
    );

    expect($resultat['nature'])->toBeNull()
        ->and($resultat['confiance'])->toBe('a_verifier')
        ->and($resultat['libelle'])->toBe("En application des dispositions de l'article 10 du règlement, la séance est levée");
});

it('ne recopie jamais les visas dans le libellé de repli', function () {
    // Constaté le 16/08/2026 : sans coupure, le repli emportait « La Cour
    // constitutionnelle, Saisie par lettre n° 0280/AN/P-CAB… ».
    $resultat = $this->extracteur->proposer(
        'du règlement intérieur du Parlement reuni en Congres La Cour constitutionnelle, '
        .'Saisie par lettre n° 0280/AN/P-CAB, en date du 3 mai 2022'
    );

    expect($resultat['libelle'])->not->toContain('Cour constitutionnelle')
        ->and($resultat['libelle'])->not->toContain('Saisie par lettre');
});

it('tronque sur un mot entier au-delà de la longueur maximale', function () {
    $resultat = $this->extracteur->proposer(
        'portant '.str_repeat('organisation des services déconcentrés ', 12).'du ministère.'
    );

    expect(mb_strlen($resultat['libelle']))->toBeLessThanOrEqual(LibelleDescriptifExtractor::LONGUEUR_MAX)
        ->and($resultat['libelle'])->toEndWith('…')
        ->and($resultat['confiance'])->toBe('a_verifier');
});

dataset('corps sans libellé possible', [
    'dispositif vide' => ['Arrête :'],
    'chaîne vide' => [''],
    'pagination seule' => ['[[MIBEKO_PAGE:15]]'],
    'bruit sans lettre' => ['1026 — 17/2009 —'],
]);

it('écarte un corps dont rien ne peut être tiré', function (string $corps) {
    $resultat = $this->extracteur->proposer($corps);

    expect($resultat['libelle'])->toBeNull()
        ->and($resultat['motif_rejet'])->not->toBeNull();
})->with('corps sans libellé possible');

it('écarte un corps absent', function () {
    expect($this->extracteur->proposer(null)['motif_rejet'])->toBe('corps_vide');
});
