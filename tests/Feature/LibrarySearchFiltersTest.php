<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

/**
 * Couvre les filtres serveur ajoutés à la recherche de la Bibliothèque :
 * périmètre juridique (`legal_scope`), institution, et structure enrichie.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    AnonymousAgent::fake(['Réponse IA mockée']);

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);

    $this->ohadaInstitution = Institution::factory()->create([
        'nom' => 'OHADA', 'sigle' => 'OHADA',
    ]);

    // Document national congolais
    $this->docNational = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi nationale sur le contrat de travail',
        'legal_scope' => 'national',
    ]);
    $articleNational = Article::factory()->create([
        'document_id' => $this->docNational->id,
        'numero_article' => '10',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $articleNational->id,
        'contenu_texte' => 'Le contrat de travail national obéit aux règles suivantes.',
        'validity_period' => '[2020-01-01,)',
    ]);

    // Document OHADA
    $this->docOhada = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Acte uniforme OHADA relatif au contrat commercial',
        'legal_scope' => 'ohada',
        'institution_id' => $this->ohadaInstitution->id,
    ]);
    $articleOhada = Article::factory()->create([
        'document_id' => $this->docOhada->id,
        'numero_article' => '20',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $articleOhada->id,
        'contenu_texte' => 'Le contrat commercial OHADA est soumis à ces dispositions.',
        'validity_period' => '[2020-01-01,)',
    ]);
});

it('filtre les résultats par périmètre OHADA', function () {
    $response = $this->getJson('/api/v1/articles/search?q=contrat&legal_scope=ohada');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '20')
        ->assertJsonPath('data.0.legal_scope', 'ohada');
});

it('filtre les résultats par périmètre national', function () {
    $response = $this->getJson('/api/v1/articles/search?q=contrat&legal_scope=national');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '10')
        ->assertJsonPath('data.0.legal_scope', 'national');
});

it('filtre les résultats par institution', function () {
    $institutionId = $this->ohadaInstitution->id;
    $response = $this->getJson("/api/v1/articles/search?q=contrat&institution_id={$institutionId}");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '20')
        ->assertJsonPath('data.0.institution', 'OHADA');
});

it('expose les champs périmètre, institution et date dans les résultats', function () {
    $response = $this->getJson('/api/v1/articles/search?q=contrat');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'number', 'content', 'document_id', 'document_title',
                    'breadcrumb', 'legal_scope', 'institution_id', 'institution',
                    'date_publication', 'score',
                ],
            ],
        ]);
});

it('rejette un périmètre invalide', function () {
    $response = $this->getJson('/api/v1/articles/search?q=contrat&legal_scope=france');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['legal_scope']);
});
