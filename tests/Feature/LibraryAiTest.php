<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Str;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Sanctum\Sanctum;

/**
 * Couvre la couche IA « à la demande » de la Bibliothèque : `explain` (un
 * article) et `synthesis` (une recherche), toutes deux en streaming SSE et
 * sans état. Le protocole SSE doit émettre `event: sources`, le texte streamé,
 * puis `[DONE]`.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    AnonymousAgent::fake(['Voici la synthèse Mibeko.']);

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);

    $doc = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi sur le contrat de travail',
        'legal_scope' => 'national',
    ]);
    $this->article = Article::factory()->create([
        'document_id' => $doc->id,
        'numero_article' => '10',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article->id,
        'contenu_texte' => 'Le contrat de travail obéit aux règles suivantes.',
        'validity_period' => '[2020-01-01,)',
    ]);

    Sanctum::actingAs(User::factory()->create());
});

it('explique un article en streaming SSE', function () {
    $response = $this->postJson('/api/v1/library/explain', [
        'article_id' => $this->article->id,
    ]);

    $response->assertStatus(200);
    expect($response->headers->get('content-type'))->toContain('text/event-stream');

    $content = $response->streamedContent();
    expect($content)->toContain('event: sources');
    // Le fake streame mot par mot : on vérifie le type de frame + un mot du texte.
    expect($content)->toContain('text_delta');
    expect($content)->toContain('Voici');
    expect($content)->toContain('[DONE]');
});

it('refuse un article inexistant', function () {
    $response = $this->postJson('/api/v1/library/explain', [
        'article_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['article_id']);
});

it('synthétise une recherche en streaming SSE', function () {
    $response = $this->postJson('/api/v1/library/synthesis', [
        'q' => 'contrat',
    ]);

    $response->assertStatus(200);
    expect($response->headers->get('content-type'))->toContain('text/event-stream');

    $content = $response->streamedContent();
    expect($content)->toContain('event: sources');
    expect($content)->toContain('text_delta');
    expect($content)->toContain('Voici');
    expect($content)->toContain('[DONE]');
});

it('émet une erreur SSE quand aucune source ne correspond', function () {
    $response = $this->postJson('/api/v1/library/synthesis', [
        'q' => 'xylophonezzz',
    ]);

    $response->assertStatus(200);

    $content = $response->streamedContent();
    expect($content)->toContain('event: error');
    expect($content)->toContain('[DONE]');
});
