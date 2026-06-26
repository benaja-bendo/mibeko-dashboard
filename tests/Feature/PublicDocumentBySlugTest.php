<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentRelation;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Observers\ArticleVersionObserver;
use Laravel\Ai\Embeddings;

/**
 * Couvre la vue publique par slug (`/legal-documents/slug/{slug}`) qui alimente
 * les pages SEO du site vitrine : génération du slug, accès au texte d'un
 * article, et invisibilité des brouillons.
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    DocumentType::firstOrCreate(['code' => 'CODE'], ['nom' => 'Code']);
});

function publishedCodeWithArticle(string $title, string $articleNumber, string $content): LegalDocument
{
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => $title,
        'curation_status' => 'published',
    ]);

    $article = Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => $articleNumber,
        'ordre_affichage' => 1,
    ]);

    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'contenu_texte' => $content,
        'validity_period' => '[2020-01-01,)',
    ]);

    return $document->refresh();
}

it('génère automatiquement un slug lisible à la création', function () {
    $document = publishedCodeWithArticle('Code de la Famille', '1', 'Texte.');

    expect($document->slug)->toBe('code-de-la-famille');
});

it('déduplique les slugs des documents homonymes', function () {
    $first = publishedCodeWithArticle('Code du Travail', '1', 'A');
    $second = publishedCodeWithArticle('Code du Travail', '1', 'B');

    expect($first->slug)->toBe('code-du-travail');
    expect($second->slug)->toBe('code-du-travail-2');
});

it('expose un document publié par son slug avec l\'index des articles', function () {
    $document = publishedCodeWithArticle('Code de la Famille', '230', 'Le mariage est…');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertStatus(200)
        ->assertJsonPath('data.document.slug', 'code-de-la-famille')
        ->assertJsonPath('data.document.titre_officiel', 'Code de la Famille')
        ->assertJsonPath('data.articles.0.number', '230')
        ->assertJsonPath('data.current_article', null);
});

it('renvoie le texte intégral de l\'article demandé', function () {
    $document = publishedCodeWithArticle('Code de la Famille', '230', 'Le mariage est l\'union…');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}?article=230")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.number', '230')
        ->assertJsonPath('data.current_article.content', 'Le mariage est l\'union…');
});

it('expose le sommaire hiérarchique et l\'indicateur PDF', function () {
    $document = publishedCodeWithArticle('Code Forestier', '12', 'Texte.');

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertStatus(200)
        ->assertJsonPath('data.has_pdf', false)
        ->assertJsonPath('data.structure.nodes.0.parent_id', null)
        ->assertJsonPath('data.structure.nodes.0.articles.0.number', '12');
});

it('reconstruit la hiérarchie parent-enfant du sommaire depuis le tree_path', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Code de Test Hiérarchie',
        'curation_status' => 'published',
    ]);

    $parent = StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'LIVRE',
        'numero' => 'PREMIER',
        'tree_path' => 'n_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa',
        'sort_order' => 0,
    ]);
    $child = StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'CHAPITRE',
        'numero' => 'I',
        'tree_path' => 'n_aaaaaaaa_aaaa_aaaa_aaaa_aaaaaaaaaaaa.n_bbbbbbbb_bbbb_bbbb_bbbb_bbbbbbbbbbbb',
        'sort_order' => 1,
    ]);

    Article::factory()->create([
        'document_id' => $document->id,
        'numero_article' => '1',
        'ordre_affichage' => 1,
        'parent_node_id' => $child->id,
    ]);

    $nodes = collect(
        $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
            ->assertStatus(200)
            ->json('data.structure.nodes')
    )->keyBy('id');

    expect($nodes[$parent->id]['parent_id'])->toBeNull();
    expect($nodes[$child->id]['parent_id'])->toBe($parent->id);
    expect($nodes[$child->id]['articles'][0]['number'])->toBe('1');
});

it('ne rend pas accessible un document non publié', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Brouillon Interne',
        'curation_status' => 'draft',
    ]);

    $this->getJson("/api/v1/legal-documents/slug/{$document->slug}")
        ->assertStatus(404);
});

it('expose les textes liés publiés d\'un article (maillage interne)', function () {
    $source = publishedCodeWithArticle('Code Civil', '10', 'Voir la loi sur les sociétés.');
    $target = publishedCodeWithArticle('Loi sur les Sociétés', '5', 'Dispositions applicables.');

    DocumentRelation::create([
        'source_doc_id' => $source->id,
        'target_doc_id' => $target->id,
        'source_article_id' => $source->articles()->first()->id,
        'target_article_id' => $target->articles()->first()->id,
        'relation_type' => 'CITE',
    ]);

    $this->getJson("/api/v1/legal-documents/slug/{$source->slug}?article=10")
        ->assertStatus(200)
        ->assertJsonPath('data.current_article.related.0.document_slug', 'loi-sur-les-societes')
        ->assertJsonPath('data.current_article.related.0.article_number', '5')
        ->assertJsonPath('data.current_article.related.0.type', 'CITE');
});

it('ignore les textes liés non publiés (pas de lien mort)', function () {
    $source = publishedCodeWithArticle('Code de Commerce', '1', 'Référence.');

    $targetDraft = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'titre_officiel' => 'Texte non publié',
        'curation_status' => 'draft',
    ]);
    $targetArticle = Article::factory()->create(['document_id' => $targetDraft->id, 'numero_article' => '1']);

    DocumentRelation::create([
        'source_doc_id' => $source->id,
        'target_doc_id' => $targetDraft->id,
        'source_article_id' => $source->articles()->first()->id,
        'target_article_id' => $targetArticle->id,
        'relation_type' => 'CITE',
    ]);

    $this->getJson("/api/v1/legal-documents/slug/{$source->slug}?article=1")
        ->assertStatus(200)
        ->assertJsonCount(0, 'data.current_article.related');
});
