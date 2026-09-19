<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Le texte modificateur (`modifie_par_document_id`, dashboard#166) ne sert à
 * rien s'il reste invisible : c'est ce qui permet à un éditeur de vérifier un
 * amendement contre le Journal officiel plutôt que de le supposer. Ce test
 * verrouille son exposition par l'arbre (`GET legal-documents/{id}/tree`),
 * consommé par le viewer mibeko-front.
 */
beforeEach(function () {
    $this->editor = User::factory()->create();
    $this->editor->assignRole(Role::findOrCreate('editor'));
});

it('expose le texte modificateur d\'une version amendée par l\'arbre', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create(['document_id' => $document->id, 'sort_order' => 0]);
    $modificateur = LegalDocument::factory()->create(['titre_officiel' => 'Décret n° 2026-42 du 1 janvier 2026.']);

    $article = Article::create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
        'numero_article' => '1',
        'ordre_affichage' => 1,
        'validation_status' => 'validated',
    ]);
    ArticleVersion::factory()->create([
        'article_id' => $article->id,
        'modifie_par_document_id' => $modificateur->id,
    ]);

    $reponse = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertOk();

    $version = collect($reponse->json('data.0.articles.0.versions'))->first();

    expect($version['modifie_par_document_id'])->toBe($modificateur->id)
        ->and($version['modifie_par_document_titre'])->toBe('Décret n° 2026-42 du 1 janvier 2026.');
});

it('ne renseigne aucun texte modificateur pour une correction (jamais forkée)', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create(['document_id' => $document->id, 'sort_order' => 0]);

    $article = Article::create([
        'document_id' => $document->id,
        'parent_node_id' => $node->id,
        'numero_article' => '1',
        'ordre_affichage' => 1,
        'validation_status' => 'validated',
    ]);
    ArticleVersion::factory()->create(['article_id' => $article->id]);

    $reponse = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertOk();

    $version = collect($reponse->json('data.0.articles.0.versions'))->first();

    expect($version['modifie_par_document_id'])->toBeNull()
        ->and($version['modifie_par_document_titre'])->toBeNull();
});
