<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * `mibeko:proposer-titres-nettoyes` : nettoie par RETRAIT, jamais par ajout.
 *
 * Le test central de ce fichier est le dernier : aucune proposition ne peut
 * contenir un caractère absent du titre d'origine. C'est la garantie qui sépare
 * un nettoyage d'une réécriture de texte juridique.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'DEC'], ['nom' => 'Décret']);
});

function documentAvecTitre(string $titre, string $statut = 'published'): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'DEC',
        'titre_officiel' => $titre,
        'document_role' => 'FLUX',
        'curation_status' => $statut,
        'official_journal_id' => null,
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => 'Unique',
        'ordre_affichage' => 1,
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Contenu de test.',
        'validity_period' => '[2020-01-01,)',
    ]);

    return $document;
}

/**
 * @return array{propositions: list<array<string, mixed>>, ecartes: list<array<string, mixed>>}
 */
function nettoyer(): array
{
    $chemin = storage_path('app/test-nettoyage-'.uniqid().'.json');

    test()->artisan('mibeko:proposer-titres-nettoyes', [
        '--connection' => 'pgsql',
        '--out' => $chemin,
    ])->assertExitCode(0);

    $rapport = json_decode((string) file_get_contents($chemin), true);
    unlink($chemin);

    return $rapport;
}

function propositionPour(array $rapport, string $documentId): ?array
{
    return collect($rapport['propositions'])->firstWhere('id', $documentId);
}

it("retire le corps de l'acte entré dans l'intitulé", function () {
    $document = documentAvecTitre(
        'Décret n° 2025-203 du 3 juin 2025 M. GABA (Richard), administrateur des SAF est nommé directeur '
        .'des systèmes d’information et de la communication. L’intéressé percevra les indemnités prévues par les textes en vigueur.',
    );

    $proposition = propositionPour(nettoyer(), $document->id);

    expect($proposition['titre'])->toBe('Décret n° 2025-203 du 3 juin 2025');
    expect($proposition['operations'])->toContain('corps_retire');
});

it("n'ampute jamais l'objet, qui prolonge le titre en minuscule après la date", function () {
    // Régression : la première version coupait à la date et détruisait l'objet
    // de 36 intitulés corrects, dont ce décret donné en exemple par le fondateur.
    $document = documentAvecTitre(
        'Décret n° 2025-359 du 21 août 2025 portant attributions, composition et fonctionnement du comité '
        ."interministériel de la décentralisation en matière d'enseignement préscolaire, primaire et secondaire",
    );

    $proposition = propositionPour(nettoyer(), $document->id);

    expect($proposition)->toBeNull();
});

it('recolle une césure sans toucher aux mots composés', function () {
    $cesure = documentAvecTitre(
        'Décret n° 2025-359 du 21 août 2025 portant fonctionnement du comi- té interministériel de la décentralisation',
    );
    $compose = documentAvecTitre('Décret n° 2025-360 du 22 août 2025 portant statut des sous-officiers de police');

    $rapport = nettoyer();

    expect(propositionPour($rapport, $cesure->id)['titre'])
        ->toContain('du comité interministériel');
    expect(propositionPour($rapport, $compose->id))->toBeNull();
});

it("retire le numéro de page du JO mais respecte le millésime de l'acte", function () {
    $page = documentAvecTitre("Décret n° 70-8 du 14 janvier 1970, portant nomination dans l'Ordre du Mérite Congolais. 34");
    $annee = documentAvecTitre("Arrêté n° 4603 du 6 octobre 2025 portant ouverture du concours au titre de l'année 2025");

    $rapport = nettoyer();

    expect(propositionPour($rapport, $page->id)['titre'])
        ->toBe("Décret n° 70-8 du 14 janvier 1970, portant nomination dans l'Ordre du Mérite Congolais.");
    expect(propositionPour($rapport, $annee->id))->toBeNull();
});

it('retire les points de conduite d\'une ligne de sommaire', function () {
    $document = documentAvecTitre("Décret n° 59-189 du 31 août 1959 relatif à l'application des circonscriptions administratives ..... 571");

    $proposition = propositionPour(nettoyer(), $document->id);

    expect($proposition['titre'])->toBe("Décret n° 59-189 du 31 août 1959 relatif à l'application des circonscriptions administratives");
});

it('convertit un motif LaTeX connu et refuse de deviner un motif inconnu', function () {
    $connu = documentAvecTitre('Avis  $\pmb{\mathrm{n}}^{\circ}$  338 de l\'Office des Changes', 'draft');
    $inconnu = documentAvecTitre('Décret $\frac{1}{2}\alpha$ 59-168 du 20 août 1959 portant organisation des services', 'draft');

    $rapport = nettoyer();

    expect(propositionPour($rapport, $connu->id)['titre'])->toBe("Avis n° 338 de l'Office des Changes");
    expect(propositionPour($rapport, $connu->id)['operations'])->toContain('espaces_resserres');
    expect(propositionPour($rapport, $inconnu->id))->toBeNull();
});

it('ne propose jamais un titre qui contiendrait un caractère absent du titre actuel', function () {
    documentAvecTitre('Décret n° 2025-203 du 3 juin 2025 M. GABA (Richard) est nommé directeur des systèmes '
        .'d’information et de la communication. L’intéressé percevra les indemnités prévues par les textes en vigueur.');
    documentAvecTitre("Décret n° 70-8 du 14 janvier 1970, portant nomination dans l'Ordre du Mérite Congolais. 34");
    documentAvecTitre('Décret n° 2025-359 du 21 août 2025 portant fonctionnement du comi- té interministériel de la décentralisation');
    documentAvecTitre('Avis $\pmb{\mathrm{n}}^{\circ}$ 338 de l\'Office des Changes', 'draft');

    $rapport = nettoyer();

    expect($rapport['propositions'])->not->toBeEmpty();
    expect($rapport['ecartes'])->toBeEmpty();

    $normaliser = function (string $texte): string {
        $texte = str_replace(
            ['$\pmb{\mathrm{n}}^{\circ}$', '$\mathbf{n}^{\circ}$', '$\mathrm{n}^{\circ}$'],
            'n°',
            $texte,
        );
        $texte = preg_replace('/([a-zà-öø-ÿ])- ([a-zà-öø-ÿ])/u', '$1$2', $texte);

        return preg_replace('/\s+/u', '', $texte);
    };

    foreach ($rapport['propositions'] as $proposition) {
        $source = $normaliser($proposition['titre_actuel']);
        $cible = $normaliser($proposition['titre']);

        // Sous-séquence stricte : impossible d'introduire un caractère nouveau.
        $curseur = 0;
        foreach (mb_str_split($cible) as $caractere) {
            $position = mb_strpos($source, $caractere, $curseur);
            expect($position)->not->toBeFalse(
                "« {$proposition['titre']} » introduit un caractère absent de « {$proposition['titre_actuel']} »",
            );
            $curseur = $position + 1;
        }
    }
});
