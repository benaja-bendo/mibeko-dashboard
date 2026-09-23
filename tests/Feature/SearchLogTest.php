<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\SearchLog;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use App\Search\SearchQueryLogger;
use App\Search\SearchSurface;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;

/**
 * mibeko-dashboard#111 : une ligne par recherche, sur les cinq points
 * d'entrée, succès comme recherche sans résultat — jamais journalisée deux
 * fois la même requête au même endroit.
 *
 * mibeko-dashboard#177 : l'usager est identifié par un VRAI jeton (en-tête
 * Authorization: Bearer), jamais par `Sanctum::actingAs`. Ce dernier bascule
 * la garde par défaut sur `sanctum` et masquait le défaut : les routes de
 * recherche sont publiques, `$request->user()` y interroge la garde `web`,
 * et 0 ligne sur 1 076 portait un `user_id` en production le 23/09/2026.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    AnonymousAgent::fake(['Réponse IA mockée']);

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);

    $document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le travail',
    ]);
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '10',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Le licenciement obéit aux règles suivantes.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;
});

it('journalise une recherche library/search avec résultat', function () {
    $this->withToken($this->token)->getJson('/api/v1/library/search?q=travail')->assertOk();

    $log = SearchLog::sole();

    expect($log->surface)->toBe(SearchSurface::LIBRARY_SEARCH);
    expect($log->query)->toBe('travail');
    expect($log->results_count)->toBeGreaterThan(0);
    expect($log->user_id)->toBe($this->user->id);
});

it('journalise une recherche library/search sans résultat', function () {
    $this->getJson('/api/v1/library/search?q=zzzqxwv')->assertOk();

    $log = SearchLog::sole();

    expect($log->results_count)->toBe(0);
});

it('journalise library/suggest en cumulant documents, articles et passages', function () {
    $this->getJson('/api/v1/library/suggest?q=travail')->assertOk();

    $log = SearchLog::sole();

    expect($log->surface)->toBe(SearchSurface::LIBRARY_SUGGEST);
});

it('journalise la surface search (mobile) distincte de articles/search', function () {
    $this->getJson('/api/v1/search?q=licenciement')->assertOk();

    $log = SearchLog::sole();

    expect($log->surface)->toBe(SearchSurface::MOBILE_SEARCH);
});

it('journalise articles/search avec sa propre surface', function () {
    $this->getJson('/api/v1/articles/search?q=licenciement')->assertOk();

    $log = SearchLog::sole();

    expect($log->surface)->toBe(SearchSurface::MOBILE_ARTICLES_SEARCH);
});

it('journalise legal-documents/search', function () {
    $this->getJson('/api/v1/legal-documents/search?q=travail')->assertOk();

    $log = SearchLog::sole();

    expect($log->surface)->toBe(SearchSurface::LEGAL_DOCUMENTS_SEARCH);
    expect($log->results_count)->toBeGreaterThan(0);
});

it('normalise la requête (espaces et casse) avant de l\'écrire', function () {
    $this->getJson('/api/v1/library/search?q='.urlencode('  TRAVAIL  '))->assertOk();

    expect(SearchLog::sole()->query)->toBe('travail');
});

it('rattache la recherche au porteur du jeton sur une route publique', function (string $url) {
    $this->withToken($this->token)->getJson($url)->assertOk();

    expect(SearchLog::sole()->user_id)->toBe($this->user->id);
})->with([
    'library/search' => '/api/v1/library/search?q=travail',
    'library/suggest' => '/api/v1/library/suggest?q=travail',
    'search' => '/api/v1/search?q=licenciement',
    'articles/search' => '/api/v1/articles/search?q=licenciement',
    'legal-documents/search' => '/api/v1/legal-documents/search?q=travail',
]);

it('journalise une recherche anonyme sans usager', function () {
    $this->getJson('/api/v1/library/search?q=travail')->assertOk();

    expect(SearchLog::sole()->user_id)->toBeNull();
});

it('ne journalise pas une recherche qui suit un lien pré-rédigé du site', function () {
    $this->withHeader(SearchQueryLogger::ORIGIN_HEADER, 'Lien')
        ->getJson('/api/v1/library/search?q='.urlencode('injonction de payer'))
        ->assertOk();

    expect(SearchLog::count())->toBe(0);
});

it('ne compte une recherche qu\'une fois, quelle que soit la page parcourue', function () {
    $this->getJson('/api/v1/library/search?q=travail&page=1')->assertOk();
    $this->getJson('/api/v1/library/search?q=travail&page=2')->assertOk();
    $this->getJson('/api/v1/legal-documents/search?q=travail&page=3')->assertOk();

    expect(SearchLog::count())->toBe(1);
});

it('ne journalise pas le gabarit SearchAction appelé tel quel par un robot', function () {
    $this->getJson('/api/v1/library/search?q='.urlencode('{search_term_string}'))->assertOk();

    expect(SearchLog::count())->toBe(0);
});
