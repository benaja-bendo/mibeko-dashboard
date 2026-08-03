<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * La publication du document est la garde éditoriale de la recherche.
 *
 * Le pipeline d'ingestion Python écrit ses articles en `pending` ; exiger un
 * `validated` article par article rendait la recherche aveugle sur la quasi-
 * totalité du corpus publié (79 versions interrogeables sur 28 978 en
 * production le 03/08/2026). Seul `error` — un contenu su fautif — reste exclu.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi']);

    $this->document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le régime des successions',
        'curation_status' => 'published',
    ]);
});

function articleAvecStatut(LegalDocument $document, string $numero, string $statut, string $texte): Article
{
    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => $numero,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $texte,
        'validation_status' => $statut,
        'validity_period' => '[2020-01-01,)',
    ]);

    return $article;
}

it('remonte un article « pending » dès lors que son document est publié', function () {
    articleAvecStatut($this->document, '731', 'pending', 'La succession est dévolue aux héritiers du défunt.');

    $response = $this->getJson('/api/v1/library/search?q=succession');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('number'))->toContain('731');
});

it('exclut un article marqué « error »', function () {
    articleAvecStatut($this->document, '999', 'error', 'La succession est dévolue aux héritiers du défunt.');

    $response = $this->getJson('/api/v1/library/search?q=succession');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('number'))->not->toContain('999');
});

it('ne remonte jamais un article dont le document n\'est pas publié', function () {
    $brouillon = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Projet de loi sur les successions',
        'curation_status' => 'draft',
    ]);
    articleAvecStatut($brouillon, '42', 'pending', 'La succession est dévolue aux héritiers du défunt.');

    $response = $this->getJson('/api/v1/library/search?q=succession');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('number'))->not->toContain('42');
});
