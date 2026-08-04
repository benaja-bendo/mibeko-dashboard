<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * `mibeko:proposer-titres` : jamais d'écriture, seulement des propositions
 * relues par un humain — voir docs/_archive/audit-prod-2026-08-04.md § 6.1.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'ARR'], ['nom' => 'Arrêté']);
});

function documentTitreTronque(string $titre, string $contenuPremierArticle): LegalDocument
{
    $doc = LegalDocument::factory()->create([
        'type_code' => 'ARR',
        'titre_officiel' => $titre,
        'curation_status' => 'published',
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1', 'ordre_affichage' => 1]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $contenuPremierArticle,
        'validity_period' => '[2020-01-01,)',
    ]);

    return $doc;
}

it('recolle le titre tronqué avec l\'incipit du premier article, jusqu\'à la formule d\'autorité', function () {
    $doc = documentTitreTronque(
        'Arrêté n° 3498 du 2 septembre 2025 portant',
        "démission de M. NGASSIE (Rufin) de ses fonctions d'huissier de justice, commissaire-priseur\n"
        ."Le garde des sceaux, ministre de la justice,\n"
        .'Vu la Constitution ;',
    );

    $chemin = storage_path('app/test-titres-proposes.json');
    $this->artisan('mibeko:proposer-titres', ['--connection' => 'pgsql', '--out' => $chemin])
        ->assertExitCode(0);

    $propositions = json_decode((string) file_get_contents($chemin), true);
    $miens = collect($propositions)->firstWhere('id', $doc->id);

    expect($miens)->not->toBeNull();
    expect($miens['titre'])->toBe(
        "Arrêté n° 3498 du 2 septembre 2025 portant démission de M. NGASSIE (Rufin) de ses fonctions d'huissier de justice, commissaire-priseur",
    );
    expect($miens['confiance'])->toBe('haute');

    unlink($chemin);
});

it('coupe sur le repère "Vu" quand il n\'y a pas de formule d\'autorité distincte', function () {
    $doc = documentTitreTronque(
        "Décision du 15 septembre 1959 fixant l'organisation du greffe de la cour arbitrale.",
        "LE PRÉSIDENT DE LA COMMUNAUTE,\n"
        .'Vu la Constitution et notamment son titre XII ;',
    );

    $chemin = storage_path('app/test-titres-proposes-2.json');
    $this->artisan('mibeko:proposer-titres', ['--connection' => 'pgsql', '--out' => $chemin])
        ->assertExitCode(0);

    $propositions = collect(json_decode((string) file_get_contents($chemin), true));
    $miens = $propositions->firstWhere('id', $doc->id);

    // Aucun repère "Le X," distinct ici : seul "Vu" coupe, et il n'a rien
    // laissé à recoller avant lui → écarté (pas de proposition farfelue).
    expect($miens)->toBeNull();

    unlink($chemin);
});

it("n'écrit jamais en base — c'est une proposition, pas une correction", function () {
    $doc = documentTitreTronque(
        'Arrêté n° 2869 du 11 août 2025 portant',
        "attribution d'un agrément pour l'exercice des activités\n"
        ."Le ministre de l'énergie,\n"
        .'Vu la Constitution ;',
    );

    $chemin = storage_path('app/test-titres-proposes-3.json');
    $this->artisan('mibeko:proposer-titres', ['--connection' => 'pgsql', '--out' => $chemin])
        ->assertExitCode(0);

    expect($doc->fresh()->titre_officiel)->toBe('Arrêté n° 2869 du 11 août 2025 portant');

    unlink($chemin);
});

it('écarte un incipit trop long (probablement un mauvais repère de coupure)', function () {
    $texteLong = 'un objet '.str_repeat('vraiment très long qui dépasse la longueur raisonnable ', 10);

    $doc = documentTitreTronque(
        'Arrêté n° 9999 du 1 janvier 2025 portant',
        $texteLong, // aucun repère "Le X," ni "Vu" dans ce texte
    );

    $chemin = storage_path('app/test-titres-proposes-4.json');
    $this->artisan('mibeko:proposer-titres', ['--connection' => 'pgsql', '--out' => $chemin])
        ->assertExitCode(0);

    $propositions = collect(json_decode((string) file_get_contents($chemin), true));
    expect($propositions->firstWhere('id', $doc->id))->toBeNull();

    unlink($chemin);
});

it('coupe avant la formule d\'adoption d\'une loi, même sans "Vu" ni "Le X," avant elle', function () {
    // Régression du 04/08/2026 : 18 lois publiées ont eu leur titre pollué
    // par cette formule (« L'Assemblée nationale a délibéré et adopté… ») car
    // aucun « Vu »/« Le X, » ne la précédait dans leur premier article.
    $doc = documentTitreTronque(
        'Loi n° 11-2025 du 28 mai 2025 portant',
        'création du centre multiservices de valorisation des bioressources'
        ."\nL'Assemblée nationale et le Sénat ont délibéré et adopté,"
        .'la loi dont la teneur suit :',
    );

    $chemin = storage_path('app/test-titres-proposes-6.json');
    $this->artisan('mibeko:proposer-titres', ['--connection' => 'pgsql', '--out' => $chemin])
        ->assertExitCode(0);

    $propositions = collect(json_decode((string) file_get_contents($chemin), true));
    $miens = $propositions->firstWhere('id', $doc->id);

    expect($miens)->not->toBeNull();
    expect($miens['titre'])->toBe(
        'Loi n° 11-2025 du 28 mai 2025 portant création du centre multiservices de valorisation des bioressources',
    );
    expect($miens['titre'])->not->toContain('Assemblée');
    expect($miens['titre'])->not->toContain('délibéré');

    unlink($chemin);
});

it('rejette un incipit qui contient du bruit de pagination du JO', function () {
    // Régression du 04/08/2026 : 8 documents publiés (dont un « Page 1095
    // Actes en abrégé… # MINISTERE… ») ont été pollués par du texte de
    // rubrique/pagination faute d'un repère « Vu »/« Le X, » avant lui.
    $doc = documentTitreTronque(
        'Décret n° 80-550/ETR-SG/DAAP/DP, portant',
        'inscription au tableau d\'avancement des fonctionnaires'
        ."\nPage 1095\n\nActes en abrégé 1096\n\n# MINISTERE DES FINANCES",
    );

    $chemin = storage_path('app/test-titres-proposes-7.json');
    $this->artisan('mibeko:proposer-titres', ['--connection' => 'pgsql', '--out' => $chemin])
        ->assertExitCode(0);

    $propositions = collect(json_decode((string) file_get_contents($chemin), true));

    // Aucun repère net avant le bruit : la proposition est écartée plutôt que
    // de polluer un titre par ailleurs correct.
    expect($propositions->firstWhere('id', $doc->id))->toBeNull();

    unlink($chemin);
});

it('ne cible plus les titres qui s\'arrêtent juste sur une ponctuation (souvent déjà complets)', function () {
    // Régression du 04/08/2026 : traiter aussi ce cas a produit 15 titres
    // pollués sur des titres déjà corrects (juste courts).
    $doc = LegalDocument::factory()->create([
        'type_code' => 'ARR',
        'titre_officiel' => 'Décret n° 59-224 du 31 octobre 1959 portant application de la loi n° 44-59 à la commune de Brazzaville.',
        'curation_status' => 'published',
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1', 'ordre_affichage' => 1]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => "'LE PREMIER MINISTRE, Sur la proposition du ministre de la jeunesse",
        'validity_period' => '[2020-01-01,)',
    ]);

    $chemin = storage_path('app/test-titres-proposes-8.json');
    $this->artisan('mibeko:proposer-titres', ['--connection' => 'pgsql', '--out' => $chemin])
        ->assertExitCode(0);

    $propositions = collect(json_decode((string) file_get_contents($chemin), true));
    expect($propositions->firstWhere('id', $doc->id))->toBeNull();

    unlink($chemin);
});

it('ignore les documents dont le titre est déjà complet', function () {
    $doc = LegalDocument::factory()->create([
        'type_code' => 'ARR',
        'titre_officiel' => 'Arrêté n° 1 du 1 janvier 2025 portant nomination de M. X',
        'curation_status' => 'published',
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1', 'ordre_affichage' => 1]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Le ministre, Vu la Constitution ;',
        'validity_period' => '[2020-01-01,)',
    ]);

    $chemin = storage_path('app/test-titres-proposes-5.json');
    $this->artisan('mibeko:proposer-titres', ['--connection' => 'pgsql', '--out' => $chemin])
        ->assertExitCode(0);

    $propositions = collect(json_decode((string) file_get_contents($chemin), true));
    expect($propositions->firstWhere('id', $doc->id))->toBeNull();

    unlink($chemin);
});
