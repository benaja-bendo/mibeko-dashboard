<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * Couvre le découplage recherche / IA : l'endpoint web de la Bibliothèque
 * (`/library/search`) est un moteur 100 % PostgreSQL — liste paginée + score,
 * JAMAIS de réponse IA, et filtres serveur respectés.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    // Si l'IA était appelée par erreur, ce texte trahirait la fuite.
    AnonymousAgent::fake(['CETTE REPONSE IA NE DOIT JAMAIS APPARAITRE.']);

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);

    $this->ohada = Institution::factory()->create(['nom' => 'OHADA', 'sigle' => 'OHADA']);

    $docNational = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi nationale sur le contrat de travail',
        'legal_scope' => 'national',
    ]);
    $articleNational = Article::factory()->create([
        'document_id' => $docNational->id,
        'numero_article' => '10',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $articleNational->id,
        'contenu_texte' => 'Le contrat de travail national obéit aux règles suivantes.',
        'validity_period' => '[2020-01-01,)',
    ]);

    $docOhada = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Acte uniforme OHADA relatif au contrat commercial',
        'legal_scope' => 'ohada',
        'institution_id' => $this->ohada->id,
    ]);
    $articleOhada = Article::factory()->create([
        'document_id' => $docOhada->id,
        'numero_article' => '20',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $articleOhada->id,
        'contenu_texte' => 'Le contrat commercial OHADA est soumis à ces dispositions.',
        'validity_period' => '[2020-01-01,)',
    ]);

    Sanctum::actingAs(User::factory()->create());
});

it('retourne une liste paginée avec score et jamais de réponse IA', function () {
    $response = $this->getJson('/api/v1/library/search?q=contrat');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id', 'number', 'content', 'document_id', 'document_title',
                    'breadcrumb', 'legal_scope', 'institution', 'date_publication', 'score',
                ],
            ],
            'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
        ])
        // Découplage IA : aucune synthèse n'est jamais renvoyée par la recherche.
        ->assertJsonMissingPath('answer')
        ->assertJsonMissingPath('data.answer')
        ->assertJsonMissingPath('data.sources');

    expect($response->json('data.0.score'))->toBeNumeric();
});

it('filtre par périmètre OHADA', function () {
    $response = $this->getJson('/api/v1/library/search?q=contrat&legal_scope=ohada');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', '20')
        ->assertJsonPath('data.0.legal_scope', 'ohada');
});

it('filtre par institution', function () {
    $institutionId = $this->ohada->id;
    $response = $this->getJson("/api/v1/library/search?q=contrat&institution_id={$institutionId}");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.institution', 'OHADA');
});

it('respecte la pagination demandée', function () {
    $response = $this->getJson('/api/v1/library/search?q=contrat&per_page=1');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('pagination.per_page', 1)
        ->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.last_page', 2);
});

it('rejette un périmètre invalide', function () {
    $response = $this->getJson('/api/v1/library/search?q=contrat&legal_scope=france');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['legal_scope']);
});

it('rejette une requête trop courte', function () {
    $response = $this->getJson('/api/v1/library/search?q=a');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});
