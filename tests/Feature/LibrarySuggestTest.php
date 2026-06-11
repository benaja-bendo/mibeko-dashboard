<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * Autocomplétion de la Bibliothèque (`/library/suggest`) : pour quelques
 * caractères tapés, l'utilisateur doit retrouver titres de textes, articles
 * par numéro ET passages du contenu — tout ce qui existe en base est trouvable.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::create(['code' => 'CODE', 'nom' => 'Code', 'niveau_hierarchique' => 1]);

    $this->codeTravail = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code du travail',
        'curation_status' => 'published',
    ]);

    $article = Article::factory()->create([
        'document_id' => $this->codeTravail->id,
        'numero_article' => '49',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Le licenciement exige un préavis dont la durée dépend de l\'ancienneté du salarié.',
        'validity_period' => '[2020-01-01,)',
    ]);

    // Document non publié : ne doit jamais remonter dans les suggestions.
    LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code du travail (révision brouillon)',
        'curation_status' => 'draft',
    ]);

    Sanctum::actingAs(User::factory()->create());
});

it('suggests published documents by title words', function () {
    $response = $this->getJson('/api/v1/library/suggest?q=code travail');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.documents')
        ->assertJsonPath('data.documents.0.title', 'Code du travail')
        ->assertJsonPath('data.documents.0.type_name', 'Code');
});

it('suggests articles by number with optional document filter', function () {
    $response = $this->getJson('/api/v1/library/suggest?q='.urlencode('article 49 travail'));

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.articles')
        ->assertJsonPath('data.articles.0.number', '49')
        ->assertJsonPath('data.articles.0.document_title', 'Code du travail');
});

it('suggests passages from the law content with highlighted snippet', function () {
    // Préfixe volontairement tronqué : la frappe en cours doit déjà matcher.
    $response = $this->getJson('/api/v1/library/suggest?q=licenciement préav');

    $response->assertStatus(200);

    $passages = $response->json('data.passages');
    expect($passages)->toHaveCount(1)
        ->and($passages[0]['document_title'])->toBe('Code du travail')
        ->and($passages[0]['snippet'])->toContain('[[');
});

it('rejects queries that are too short', function () {
    $this->getJson('/api/v1/library/suggest?q=a')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});
