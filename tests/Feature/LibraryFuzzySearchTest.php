<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * Recall flou de la Bibliothèque : le stemmer français unifie « dote » → « dot »
 * mais PAS « dotal/dotale » → « dotal ». Le filet trigram (pg_trgm) doit donc
 * rattraper un article parlant de « régime dotal » pour une recherche « dote »,
 * sans pour autant remonter les faux amis (« dotation »).
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi']);
    Sanctum::actingAs(User::factory()->create());
});

/**
 * Crée un article publié + validé avec le contenu donné, et renvoie son document.
 */
function makeArticle(string $titre, string $contenu, string $numero = '1'): LegalDocument
{
    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => $titre,
        'legal_scope' => 'national',
    ]);
    $article = Article::factory()->create([
        'document_id' => $doc->id,
        'numero_article' => $numero,
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $contenu,
        'validity_period' => '[2020-01-01,)',
    ]);

    return $doc;
}

it('remonte un article « régime dotal » pour la recherche « dote »', function () {
    $dotal = makeArticle(
        'Code de la famille',
        'Le régime dotal protège les biens propres de l\'épouse pendant le mariage.',
    );

    $response = $this->getJson('/api/v1/library/search?q=dote');

    $response->assertStatus(200);

    $documentIds = collect($response->json('data'))->pluck('document_id');
    expect($documentIds)->toContain($dotal->id);
});

it('écarte le faux ami « dotation » (budget) pour la recherche « dote »', function () {
    $dotal = makeArticle(
        'Code de la famille',
        'Le régime dotal protège les biens de l\'épouse.',
    );
    $dotation = makeArticle(
        'Loi de finances',
        'La dotation initiale de l\'établissement public est fixée par décret.',
        '2',
    );

    $response = $this->getJson('/api/v1/library/search?q=dote');

    $response->assertStatus(200);

    $documentIds = collect($response->json('data'))->pluck('document_id');
    expect($documentIds)->toContain($dotal->id)
        ->and($documentIds)->not->toContain($dotation->id);
});

it('tolère une faute de frappe (« dotte » → « la dot »)', function () {
    $dot = makeArticle(
        'Code de la famille',
        'La dot est constituée par les futurs époux avant la célébration.',
    );

    $response = $this->getJson('/api/v1/library/search?q=dotte');

    $response->assertStatus(200);

    $documentIds = collect($response->json('data'))->pluck('document_id');
    expect($documentIds)->toContain($dot->id);
});

it('remonte un article conceptuellement proche via le filet sémantique', function () {
    // Embedding de requête déterministe : toute requête renvoie ce vecteur.
    $vector = array_fill(0, 1024, 0.1);
    Embeddings::fake(fn () => [$vector]);

    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Code des successions',
        'legal_scope' => 'national',
    ]);
    $article = Article::factory()->create(['document_id' => $doc->id, 'numero_article' => '1']);
    $version = ArticleVersion::factory()->create([
        'article_id' => $article->id,
        // Aucun recoupement lexical/trigram avec la requête « héritage » :
        // seul le filet sémantique peut faire remonter cet article.
        'contenu_texte' => 'La transmission des biens au décès du de cujus obéit à des règles précises.',
        'validity_period' => '[2020-01-01,)',
    ]);

    // Embedding de l'article identique au vecteur de requête → distance cosinus 0.
    DB::update(
        'UPDATE article_versions SET embedding = ?::vector WHERE id = ?',
        ['['.implode(',', $vector).']', $version->id],
    );

    $response = $this->getJson('/api/v1/library/search?q=héritage');

    $response->assertStatus(200);

    $documentIds = collect($response->json('data'))->pluck('document_id');
    expect($documentIds)->toContain($doc->id);
});
