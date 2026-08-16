<?php

use App\Console\Commands\DetecterDefautsTitresCommand;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * `mibeko:detecter-defauts-titres` : lecture seule.
 *
 * Chaque cas « ne signale PAS » ci-dessous est un faux positif réellement
 * produit pendant la campagne de mesure du 16/08/2026, sur des documents de
 * production nommément identifiés. Ils sont ici pour qu'un élargissement futur
 * d'un détecteur les fasse échouer plutôt que de les réintroduire en silence.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'DEC'], ['nom' => 'Décret']);
    DocumentType::firstOrCreate(['code' => 'CODE'], ['nom' => 'Code']);
});

/**
 * @param  list<string>  $articles  Numéros d'articles à créer.
 */
function documentCorpus(string $titre, array $articles = ['1'], string $role = 'FLUX', string $statut = 'published'): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'type_code' => $role === 'STOCK' ? 'CODE' : 'DEC',
        'titre_officiel' => $titre,
        'document_role' => $role,
        'curation_status' => $statut,
        'stock_code' => $role === 'STOCK' ? 'code-test-'.uniqid() : null,
        'consolidation_as_of' => $role === 'STOCK' ? '2020-01-01' : null,
        'official_journal_id' => null,
    ]);

    foreach ($articles as $index => $numero) {
        $article = Article::factory()->create([
            'document_id' => $document->id,
            'numero_article' => $numero,
            'ordre_affichage' => $index + 1,
        ]);
        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => 'Contenu de test.',
            'validity_period' => '[2020-01-01,)',
        ]);
    }

    return $document;
}

/**
 * @return array<string, mixed>
 */
function detecter(): array
{
    $chemin = storage_path('app/test-defauts-'.uniqid().'.json');

    test()->artisan('mibeko:detecter-defauts-titres', [
        '--connection' => 'pgsql',
        '--exemples' => 0,
        '--json' => $chemin,
    ])->assertExitCode(0);

    $rapport = json_decode((string) file_get_contents($chemin), true);
    unlink($chemin);

    return $rapport;
}

/**
 * @param  array<string, mixed>  $rapport
 */
function familleContient(array $rapport, string $famille, string $documentId): bool
{
    return collect($rapport['familles'][$famille]['documents'] ?? [])
        ->contains(fn ($d) => $d['id'] === $documentId);
}

it('signale un faux acte : un intitulé FLUX qui commence par une minuscule', function () {
    // Cas réel : « décret en Conseil des ministres. », fragment de phrase que le
    // découpage de JO a promu en document. Un en-tête d'acte est toujours capitalisé.
    $faux = documentCorpus('décret en Conseil des ministres.', ['1'], 'FLUX', 'draft');
    $vrai = documentCorpus('Décret n° 2025-359 du 21 août 2025 portant création du comité', ['1']);

    $rapport = detecter();

    expect(familleContient($rapport, 'B1_faux_acte', $faux->id))->toBeTrue();
    expect(familleContient($rapport, 'B1_faux_acte', $vrai->id))->toBeFalse();
});

it('ne prend pas un code STOCK en minuscules pour un faux acte', function () {
    // Cas réel : « code de procedure penale (1963) ». Titre bâclé, mais document
    // légitime : le classer en faux acte le mettrait en file de suppression.
    $code = documentCorpus('code de procedure penale (1963)', ['1'], 'STOCK');

    $rapport = detecter();

    expect(familleContient($rapport, 'B1_faux_acte', $code->id))->toBeFalse();
    expect(familleContient($rapport, 'P7_stock_minuscule', $code->id))->toBeTrue();
});

it("traite l'acte en abrégé comme une observation et jamais comme un défaut", function () {
    // Cas réel vérifié contre le markdown MinerU source : le JO n'imprime aucun
    // objet pour les nominations. L'intitulé est fidèle — lui en composer un
    // serait fabriquer un titre officiel qui n'existe pas.
    $abrege = documentCorpus('Décret n° 2025-240 du 20 juin 2025.', ['Unique']);

    $rapport = detecter();

    expect(familleContient($rapport, 'I1_acte_en_abrege', $abrege->id))->toBeTrue();

    $enDefaut = collect($rapport['familles'])
        ->reject(fn ($f, $code) => str_starts_with((string) $code, 'I'))
        ->flatMap(fn ($f) => collect($f['documents'])->pluck('id'))
        ->unique();

    expect($enDefaut)->not->toContain($abrege->id);
});

it('ne signale pas un intitulé qui porte déjà son objet, même terminé par une date', function () {
    // Cas réel : loi constitutionnelle n° 2-2022. Se termine sur « … du 25 octobre
    // 2015 » et reste parfaitement complet — d'où l'exclusion par mot d'objet.
    $complet = documentCorpus(
        "Loi constitutionnelle n° 2-2022 du 7 janvier 2022 portant révision de l'article 157 de la Constitution du 25 octobre 2015",
        ['157 nouveau'],
    );

    $rapport = detecter();

    expect(familleContient($rapport, 'I1_acte_en_abrege', $complet->id))->toBeFalse();
    expect(familleContient($rapport, 'B5_page_collee', $complet->id))->toBeFalse();
});

it('signale une césure OCR mais épargne un tiret légitime', function () {
    $cesure = documentCorpus('Décret n° 2025-359 portant fonctionnement du comi- té interministériel', ['1']);
    $tiret = documentCorpus('Décret n° 2025-360 portant statut des sous-officiers de police', ['1']);

    $rapport = detecter();

    expect(familleContient($rapport, 'B6_cesure', $cesure->id))->toBeTrue();
    expect(familleContient($rapport, 'B6_cesure', $tiret->id))->toBeFalse();
});

it("signale l'intitulé qui a avalé le corps de l'acte", function () {
    $avale = documentCorpus(
        'Décret n° 2025-283 du 2 juillet 2025 M. TABAKA (Mexan Guillaume) est nommé inspecteur général '
        ."des services de l'économie forestière. M. TABAKA (Mexan Guillaume) percevra les indemnités prévues par les textes en vigueur.",
        ['Unique'],
    );

    $rapport = detecter();

    expect(familleContient($rapport, 'P1_corps_dans_titre', $avale->id))->toBeTrue();
});

it('signale une coquille vide et un acte réduit à sa signature', function () {
    $vide = documentCorpus('Décret n° 59-233 du 13 novembre 1959 portant application', [], 'FLUX', 'draft');
    $fantome = documentCorpus('Communiqué du 3 juillet 2023', ['SIGNATURE'], 'FLUX', 'draft');

    $rapport = detecter();

    expect(familleContient($rapport, 'B2_zero_article', $vide->id))->toBeTrue();
    expect(familleContient($rapport, 'B3_signature_seule', $fantome->id))->toBeTrue();
});

it("n'accuse pas un compte rendu de n'être qu'une signature", function () {
    // Cas réel : les comptes rendus du Conseil des ministres n'ont légitimement
    // qu'un PREAMBULE (le texte entier) et une SIGNATURE. 46 d'entre eux étaient
    // signalés à tort par la première version du détecteur.
    $compteRendu = documentCorpus('Compte rendu du Conseil des Ministres du 3 juillet 2024', ['PREAMBULE', 'SIGNATURE']);

    $rapport = detecter();

    expect(familleContient($rapport, 'B3_signature_seule', $compteRendu->id))->toBeFalse();
});

it('distingue un numéro de page collé au titre du millésime de l\'acte', function () {
    $page = documentCorpus("Décret n° 70-8 du 14 janvier 1970, portant nomination dans l'Ordre du Mérite Congolais. 34", ['1']);
    $annee = documentCorpus("Arrêté n° 4603 portant ouverture du concours au titre de l'année 2025", ['1']);

    $rapport = detecter();

    expect(familleContient($rapport, 'B5_page_collee', $page->id))->toBeTrue();
    expect(familleContient($rapport, 'B5_page_collee', $annee->id))->toBeFalse();
});

it('signale les résidus techniques et les lignes de sommaire', function () {
    $latex = documentCorpus('Avis  $\pmb{\mathrm{n}}^{\circ}$  338 de l\'Office des Changes', ['1'], 'FLUX', 'draft');
    $sommaire = documentCorpus('Décret n° 59-189 du 31 août 1959 relatif aux circonscriptions ..... 571', ['1']);
    $joEntier = documentCorpus('Journal officiel n° 31-2006', ['41'], 'FLUX');

    $rapport = detecter();

    expect(familleContient($rapport, 'B4_latex', $latex->id))->toBeTrue();
    expect(familleContient($rapport, 'P4_sommaire', $sommaire->id))->toBeTrue();
    expect(familleContient($rapport, 'P3_jo_entier', $joEntier->id))->toBeTrue();
});

it('ignore les documents soft-deleted et estampille la version des détecteurs', function () {
    $supprime = documentCorpus('décret fragment supprimé', ['1'], 'FLUX', 'draft');
    $supprime->delete();

    $rapport = detecter();

    expect($rapport['version_detecteurs'])->toBe(DetecterDefautsTitresCommand::VERSION);
    expect(familleContient($rapport, 'B1_faux_acte', $supprime->id))->toBeFalse();
});
