<?php

use App\Services\LatexArtifactCleaner;

/**
 * `LatexArtifactCleaner` : déséchappe ce dont on est sûr, laisse le reste.
 *
 * Toutes les entrées ci-dessous sont des formes RÉELLEMENT relevées dans le
 * corpus de développement le 07/08/2026 (`article_versions.contenu_texte` et
 * `legal_documents.titre_officiel`), pas des cas inventés.
 */
beforeEach(function () {
    $this->nettoyeur = new LatexArtifactCleaner;
});

dataset('formes converties', [
    'ordinal français' => ['le  $1^{\text{er}}$  juin 1927', 'le 1er juin 1927'],
    'ordinal sans commande' => ['le  $1^{er}$  janvier', 'le 1er janvier'],
    'ordinal romain abrégé' => ['la  $1^{\text{re}}$  chambre', 'la 1re chambre'],
    'ordinal en \\mathrm' => ['le  $4^{\mathrm{e}}$  échelon', 'le 4e échelon'],
    'énumération en degré' => ["—  \$1^{\circ}\$  La déclaration", '— 1° La déclaration'],
    'échelon' => ['commis  $4^{\circ}$  échelon', 'commis 4° échelon'],
    'numéro en gras' => ['Avis  $\mathbf{n}^{\circ}$  338', 'Avis n° 338'],
    'numéro en gothique' => ['Avis  $\mathfrak{n}^{\circ}$  342', 'Avis n° 342'],
    'numéro en pmb' => ['Avis  $\pmb{\mathrm{n}}^{\circ}$  338', 'Avis n° 338'],
    'numéro majuscule' => ['Décret  $\mathbf{N}^{\circ}$  56.674', 'Décret N° 56.674'],
    'numéro avec suite' => ['arrêté  $\mathfrak{n}^{\circ}86 - 877$  du', 'arrêté n°86 - 877 du'],
    'exposant o du numéro' => ['le  $\mathfrak{n}^{\mathrm{o}}$  37', 'le n° 37'],
    'pluriel des numéros' => ['les  $\mathbf{n}^{\text{os}}$  1 et 2', 'les nos 1 et 2'],
    'pourcentage collé' => ['plafond de  $10\%$  du total', 'plafond de 10% du total'],
    'pourcentage espacé' => ['plafond de  $7 \%$  du total', 'plafond de 7 % du total'],
    'relèvement géographique' => ["gisement à  \$276^{\circ}43'\$  du nord", "gisement à 276°43' du nord"],
    'unité en \\mathrm' => ['vitesse de  $40\mathrm{km / h}$  max', 'vitesse de 40km / h max'],
    'fraction typographique' => ['complément de  $4 / 10^{\circ}$  aux cadres', 'complément de 4 / 10° aux cadres'],
    // Indices : la lettre finale d'un mot rendue en indice par MinerU (#25).
    // La casse est accordée, jamais la lettre elle-même.
    'indice nu détaché du mot' => ['Le  $_{S}$  délégués permanents', 'Les délégués permanents'],
    'indice nu minuscule' => ['ce  $_{s}$  demandes', 'ces demandes'],
    'indice porté par un début de mot' => ['$Se_{S}$  fonctions', 'Ses fonctions'],
    // « n^{0} » avec le CHIFFRE zéro : confusion OCR avec la lettre o, déjà
    // gérée. Un même article publié porte parfois les deux graphies.
    'numéro à exposant zéro' => ['Loi  $n^{0}$  25/29', 'Loi n° 25/29'],
    'pluriel des numéros à exposant zéro' => ['les  $n^{0s}$  1 et 2', 'les nos 1 et 2'],
]);

it('déséchappe les artefacts typographiques de MinerU', function (string $avant, string $apres) {
    expect($this->nettoyeur->nettoyer($avant))->toBe($apres);
})->with('formes converties');

dataset('formes refusées', [
    'devise isolée' => 'un montant de 60 $ (cours le plus haut) à 45 $',
    'devise sans marqueur' => 'un total de $ 45.007.002 dont $ 12',
    'colonne de chiffres' => "ligne A \$100.000.000\nligne B \$125.000.000\n\$",
    'fraction' => 'la formule  $\frac{a}{b}$  donne',
    'espace fonctionnel' => 'soit  $\mathcal{L}(\mathbb{R})$  l’espace',
    'primes' => 'la mesure  $1^{\prime \prime}$  exacte',
    'bruit OCR astérisque' => 'le  $1^{*}$  janvier',
    'bruit OCR signe ±' => 'établissement  $\mathbf{e}^{\pm}$  au fonctionnement',
    'puissance algébrique' => 'la puissance  $x^{n}$  vaut',
    'appartenance' => 'seuil  $b \in \mathbb{R}^{n}$  fixé',
    'flèche' => 'flèche  $\rightarrow$  vers',
    'indice' => 'le terme  $u_{n}$  converge',
    // Frontière de la règle indice (#25) : une base d'UNE lettre est une
    // variable mathématique, jamais un début de mot.
    'indice sur variable' => 'la suite  $v_{k}$  majorée',
    'indice double' => 'le tenseur  $T_{ij}$  symétrique',
    // Numéro de loi sans préfixe « n° » : hors périmètre, cf. le service.
    'numéro sans préfixe' => 'abrogeant la loi  $15 - 62$  du 3 février',
]);

it('laisse intact ce qui n’est pas sûrement convertible', function (string $texte) {
    expect($this->nettoyeur->nettoyer($texte))->toBe($texte);
})->with('formes refusées');

it('signale ce qu’il refuse de convertir', function () {
    $analyse = $this->nettoyeur->analyser('la formule  $\frac{a}{b}$  et le  $1^{\text{er}}$  juin');

    expect($analyse['texte'])->toBe('la formule  $\frac{a}{b}$  et le 1er juin');
    expect($analyse['convertis'])->toBe([['avant' => '$1^{\text{er}}$', 'apres' => '1er']]);
    expect($analyse['refuses'])->toContain('$\frac{a}{b}$');
});

it('n’apparie jamais deux « $ » séparés par un saut de ligne', function () {
    // Les tableaux de montants d'une convention minière alignent un « $ » par
    // ligne : les apparier effacerait des devises et fusionnerait des lignes.
    $texte = "PRIX\n\$1.582.400.000\n\$918.000.000\n";

    expect($this->nettoyeur->nettoyer($texte))->toBe($texte);
});

it('est idempotent : un texte déjà nettoyé ne bouge plus', function () {
    $une = $this->nettoyeur->nettoyer('le  $1^{\text{er}}$  juin, article  $2^{\circ}$');

    expect($this->nettoyeur->nettoyer($une))->toBe($une);
});

it('préserve l’espacement d’origine sans jamais en inventer', function () {
    expect($this->nettoyeur->nettoyer('du$1^{\text{er}}$juin'))->toBe('du1erjuin');
    expect($this->nettoyeur->nettoyer('du $1^{\text{er}}$ juin'))->toBe('du 1er juin');
});

it('laisse le texte sans LaTeX rigoureusement inchangé', function () {
    $texte = "Article 1er : la présente loi entre en vigueur le 1° janvier.\n\nFait à Brazzaville.";

    expect($this->nettoyeur->nettoyer($texte))->toBe($texte);
});

/**
 * mibeko-dashboard#24 — régression trouvée le 09/08/2026 en vérifiant, avant
 * exécution, le mapping produit par `mibeko:proposer-nettoyage-latex` sur la
 * production : quand MinerU laisse le chiffre EN DEHORS du `$` et n'ouvre le
 * mode mathématique que pour l'exposant seul, le nettoyeur reproduisait le
 * blanc de séparation au lieu de coller le chiffre à l'exposant. Les 7 formes
 * ci-dessous sont les 7 occurrences réelles trouvées sur les 128 candidats du
 * lot de correction (`article_versions.contenu_texte`, versions publiées),
 * pas des cas inventés.
 */
dataset('base collée à un exposant nu (régression #24)', [
    'ordinal « er » simple' => [
        'paragraphe 1  $^{er}$  de la présente loi.',
        'paragraphe 1er de la présente loi.',
    ],
    'ordinal « er » en milieu de phrase' => [
        'les alinéas 1  $^{er}$  et 2 sont applicables',
        'les alinéas 1er et 2 sont applicables',
    ],
    'ordinal « er » suivi d’un point isolé' => [
        'du livre 1  $^{er}$  .',
        'du livre 1er .',
    ],
    'ordinal « ème »' => [
        'au-delà du 8  $^{ème}$  degré',
        'au-delà du 8ème degré',
    ],
    'ordinal « ème », variante espacement' => [
        'né avant le 180  $^{ème}$  jour',
        'né avant le 180ème jour',
    ],
]);

it('colle le chiffre externe au $ à l’exposant nu qu’il précède', function (string $avant, string $apres) {
    expect($this->nettoyeur->nettoyer($avant))->toBe($apres);
})->with('base collée à un exposant nu (régression #24)');

it('ne colle PAS quand la base est déjà à l’intérieur du $ (non-régression #24)', function () {
    // Cas déjà correct avant le correctif : la base ET l'exposant sont tous
    // deux dans le `$` — rien ne doit changer dans ce cas, seul le cas où la
    // base est HORS du `$` (dataset ci-dessus) était fautif.
    expect($this->nettoyeur->nettoyer('le  $1^{\text{er}}$  juin 1927'))
        ->toBe('le 1er juin 1927');
});

it('ne colle pas un chiffre externe à autre chose qu’un exposant nu', function () {
    // Le chiffre "5" précède ici une portion qui n'est PAS un exposant nu
    // (elle contient \mathfrak{n}) : la règle de collage ne doit pas
    // s'appliquer, seul le comportement espacement habituel doit jouer.
    expect($this->nettoyeur->nettoyer('acte 5  $\mathfrak{n}^{\circ}$  du registre'))
        ->toBe('acte 5 n° du registre');
});
