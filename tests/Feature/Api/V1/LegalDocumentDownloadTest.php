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

it('transporte les tableaux structurés jusqu\'au corpus hors-ligne', function () {
    // Sans ce champ, un téléphone n'a que la forme linéarisée (« A | B | C ») :
    // le texte reste lisible mais la colonne se perd, et l'app ne peut plus
    // rendre de vrai tableau (mibeko-python#12).
    $document = LegalDocument::factory()->create();

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => 'TABLEAU_1',
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => "Chapitre | Montant\n3-2-1 | 50.000.000",
        'source_locator' => [
            'content_format' => 'table',
            'tables' => [[
                'caption' => 'Crédits ouverts',
                'headers' => ['Chapitre', 'Montant'],
                'rows' => [['3-2-1', '50.000.000']],
                'line_start' => 0,
                'line_end' => 2,
                // Provenance : ne doit JAMAIS partir vers un corpus hors-ligne.
                'html_source' => '<table><tr><td>Chapitre</td></tr></table>',
            ]],
        ],
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/download")
        ->assertStatus(200)
        ->assertJsonPath('data.articles.0.content_format', 'table')
        ->assertJsonPath('data.articles.0.tables.0.caption', 'Crédits ouverts')
        ->assertJsonPath('data.articles.0.tables.0.headers', ['Chapitre', 'Montant'])
        ->assertJsonPath('data.articles.0.tables.0.rows.0', ['3-2-1', '50.000.000'])
        ->assertJsonPath('data.articles.0.tables.0.line_start', 0);

    expect($response->json('data.articles.0.tables.0'))->not->toHaveKey('html_source');
});

it('n\'ajoute pas de tableau vide à un article ordinaire', function () {
    $document = LegalDocument::factory()->create();
    $article = Article::factory()->create(['document_id' => $document->id]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => 'Le présent décret entre en vigueur.',
        'source_locator' => ['page' => 3],
    ]);

    $this->getJson("/api/v1/legal-documents/{$document->id}/download")
        ->assertStatus(200)
        ->assertJsonPath('data.articles.0.content_format', null)
        ->assertJsonPath('data.articles.0.tables', []);
});
