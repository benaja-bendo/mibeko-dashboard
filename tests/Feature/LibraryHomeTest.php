<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * Couvre l'accueil de la Bibliothèque (`/library/home`) : textes fondamentaux,
 * derniers textes publiés, statistiques du fonds et suggestions de recherche.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Cache::flush();

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    DocumentType::create(['code' => 'CODE', 'nom' => 'Code']);

    Institution::factory()->create(['nom' => 'Ministère de la Justice']);

    $code = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code du Travail',
        'curation_status' => 'published',
        'date_publication' => '2015-03-10',
    ]);
    $codeArticle = Article::factory()->create(['document_id' => $code->id]);
    ArticleVersion::factory()->create([
        'article_id' => $codeArticle->id,
        'contenu_texte' => 'Le contrat de travail est régi par le présent code.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $loi = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi récente sur le numérique',
        'curation_status' => 'published',
        'date_publication' => '2026-01-15',
    ]);
    $loiArticle = Article::factory()->create(['document_id' => $loi->id]);
    ArticleVersion::factory()->create([
        'article_id' => $loiArticle->id,
        'contenu_texte' => 'Dispositions relatives au numérique.',
        'validity_period' => '[2026-01-15,)',
    ]);

    // Document non publié : ne doit apparaître nulle part.
    LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Brouillon non publié',
        'curation_status' => 'draft',
    ]);

    Sanctum::actingAs(User::factory()->create());
});

it('retourne stats, textes fondamentaux, récents et suggestions', function () {
    $response = $this->getJson('/api/v1/library/home');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'stats' => ['documents', 'articles', 'institutions'],
                'essential_documents' => [
                    '*' => ['id', 'title', 'type_code', 'type_name', 'legal_scope', 'date_publication', 'articles_count'],
                ],
                'recent_documents',
                'suggestions',
            ],
        ])
        ->assertJsonPath('data.stats.documents', 2)
        ->assertJsonPath('data.stats.articles', 2);

    // Le Code du Travail est un texte fondamental ; le brouillon est exclu.
    $essentialTitles = collect($response->json('data.essential_documents'))->pluck('title');
    expect($essentialTitles)->toContain('Code du Travail')
        ->not->toContain('Brouillon non publié');

    // Les récents sont triés par date de publication décroissante.
    expect($response->json('data.recent_documents.0.title'))->toBe('Loi récente sur le numérique');

    expect($response->json('data.suggestions'))->toBeArray()->not->toBeEmpty();
});

it('exclut les documents non publiés des récents', function () {
    $titles = collect($this->getJson('/api/v1/library/home')->json('data.recent_documents'))
        ->pluck('title');

    expect($titles)->not->toContain('Brouillon non publié');
});

it('est accessible sans authentification (lecture publique partagée web/mobile)', function () {
    auth()->forgetGuards();

    $this->getJson('/api/v1/library/home')->assertOk();
});
