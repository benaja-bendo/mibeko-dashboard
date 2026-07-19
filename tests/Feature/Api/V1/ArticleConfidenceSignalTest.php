<?php

use App\Http\Resources\V1\LegalDocumentResource;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Signal de confiance HONNÊTE exposé côté API (P2.10).
 *
 * Deux garanties :
 *  - la borne basse de validité de la version active (`validity_start`) est
 *    exposée telle qu'enregistrée ;
 *  - la relecture juriste (`reviewed_at`) n'est exposée QUE si elle a réellement
 *    eu lieu — null par défaut, jamais de « vérifié par un juriste » inventé.
 */
beforeEach(function () {
    $this->editor = User::factory()->create();
    $this->editor->assignRole(Role::findOrCreate('editor'));
});

/**
 * Crée un article publié avec une version active (upper bound ouverte).
 */
function publishedArticleWithActiveVersion(array $versionAttributes = []): Article
{
    $document = LegalDocument::factory()->create(['curation_status' => 'published']);
    $article = Article::factory()->forDocument($document->id)->create([
        'validation_status' => 'validated',
    ]);

    ArticleVersion::factory()->create(array_merge([
        'article_id' => $article->id,
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-03-15'),
        'validation_status' => 'validated',
    ], $versionAttributes));

    return $article;
}

it('parses the validity_period lower bound as validity_start', function () {
    $version = new ArticleVersion(['validity_period' => '[2019-06-01,)']);
    expect($version->validity_start)->toBe('2019-06-01');

    $bounded = new ArticleVersion(['validity_period' => '[2019-06-01,2022-01-01)']);
    expect($bounded->validity_start)->toBe('2019-06-01');
});

it('returns null validity_start when the lower bound is absent', function () {
    // Borne basse ouverte (rare mais possible) : aucune date à exposer.
    $version = new ArticleVersion(['validity_period' => '(,2022-01-01)']);
    expect($version->validity_start)->toBeNull();

    expect((new ArticleVersion(['validity_period' => '']))->validity_start)->toBeNull();
});

it('exposes validity_start on the article resource', function () {
    $article = publishedArticleWithActiveVersion();

    $this->actingAs($this->editor)
        ->getJson("/api/v1/articles/{$article->id}")
        ->assertOk()
        ->assertJsonPath('data.validity_start', '2020-03-15');
});

it('exposes reviewed_at as null by default (no invented verification)', function () {
    // Aucune relecture juriste : le signal est présent mais VIDE, jamais peuplé
    // par défaut. Le client n'affichera donc rien de trompeur.
    $article = publishedArticleWithActiveVersion();

    $this->actingAs($this->editor)
        ->getJson("/api/v1/articles/{$article->id}")
        ->assertOk()
        ->assertJsonPath('data.reviewed_at', null);
});

it('exposes reviewed_at once a jurist review is genuinely recorded', function () {
    $jurist = User::factory()->create();
    $reviewedAt = now()->subDays(3)->startOfSecond();

    $article = publishedArticleWithActiveVersion([
        'reviewed_by' => $jurist->id,
        'reviewed_at' => $reviewedAt,
    ]);

    $response = $this->actingAs($this->editor)
        ->getJson("/api/v1/articles/{$article->id}")
        ->assertOk();

    expect($response->json('data.reviewed_at'))->toBe($reviewedAt->toIso8601String());
});

it('exposes the confidence signals on the nested document article resource', function () {
    // Parcours réel du site/app : document -> articles (ArticleSyncResource).
    $jurist = User::factory()->create();
    $reviewedAt = now()->subDay()->startOfSecond();

    $document = LegalDocument::factory()->create(['curation_status' => 'published']);
    $article = Article::factory()->forDocument($document->id)->create([
        'validation_status' => 'validated',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validity_period' => ArticleVersion::makeValidityPeriod('2018-01-01'),
        'validation_status' => 'validated',
        'reviewed_by' => $jurist->id,
        'reviewed_at' => $reviewedAt,
    ]);

    // On charge la version active pour que les signaux soient sérialisés.
    $document->load('articles.activeVersion');

    $payload = (new LegalDocumentResource($document))
        ->toArray(request());

    $articleData = collect($payload['articles']->resolve())->firstWhere('id', $article->id);

    expect($articleData['validity_start'])->toBe('2018-01-01')
        ->and($articleData['reviewed_at'])->toBe($reviewedAt->toIso8601String());
});
