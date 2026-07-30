<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can download a legal document for offline sync', function () {
    $document = LegalDocument::factory()->create();

    $node = StructureNode::factory()->create([
        'document_id' => $document->id,
        'titre' => 'Titre 1',
        'type_unite' => 'TITRE',
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'validation_status' => 'validated',
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/download");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resource_id', $document->id)
        ->assertJsonCount(1, 'data.nodes')
        ->assertJsonCount(1, 'data.articles');
});

it('inclut les articles orphelins dans le téléchargement complet', function () {
    $document = LegalDocument::factory()->create();

    $node = StructureNode::factory()->create([
        'document_id' => $document->id,
        'titre' => 'Titre 1',
        'type_unite' => 'TITRE',
    ]);

    $attached = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $attached->id,
        'validation_status' => 'validated',
    ]);

    // Article sans nœud parent : fréquent sur les textes courts. Il était
    // silencieusement absent du téléchargement, donc du corpus hors-ligne.
    $orphan = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => null,
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $orphan->id,
        'validation_status' => 'validated',
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/download");

    $response->assertStatus(200)->assertJsonCount(2, 'data.articles');

    expect(collect($response->json('data.articles'))->pluck('id'))
        ->toContain($orphan->id)
        ->toContain($attached->id);
});

it('exclut les articles orphelins quand un sous-arbre précis est demandé', function () {
    $document = LegalDocument::factory()->create();

    $node = StructureNode::factory()->create([
        'document_id' => $document->id,
        'titre' => 'Titre 1',
        'type_unite' => 'TITRE',
    ]);

    $attached = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $attached->id,
        'validation_status' => 'validated',
    ]);

    $orphan = Article::factory()->create([
        'document_id' => $document->id,
        'parent_node_id' => null,
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $orphan->id,
        'validation_status' => 'validated',
    ]);

    // Un orphelin n'appartient à aucun nœud : le télécharger avec un sous-arbre
    // ciblé donnerait un contenu que l'utilisateur n'a pas demandé.
    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/download?node_id={$node->id}");

    $response->assertStatus(200)->assertJsonCount(1, 'data.articles');

    expect(collect($response->json('data.articles'))->pluck('id'))
        ->toContain($attached->id)
        ->not->toContain($orphan->id);
});
