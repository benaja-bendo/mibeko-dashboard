<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Rang d'affichage des articles — régression constatée en production le 11/08/2026
 * en récupérant des articles fusionnés par l'OCR.
 *
 * Deux défauts corrigés ensemble :
 *  - l'arbre ne triait pas les articles d'une division : Postgres les renvoyait
 *    dans l'ordre physique du heap, que tout INSERT/UPDATE déplace. Un article
 *    simplement corrigé depuis l'éditeur sautait en fin de division.
 *  - impossible de créer un article sur un acte court (aucune division) : le
 *    contrôleur exigeait `parent_node_id`, alors que ces articles sont rattachés
 *    directement au document et que l'arbre sait déjà les afficher.
 */
beforeEach(function () {
    $this->editor = User::factory()->create();
    $this->editor->assignRole(Role::findOrCreate('editor'));
});

/**
 * Création directe : la factory rattache d'office un nœud de structure à tout
 * article qui n'en a pas, ce qui rend l'article orphelin impossible à fabriquer.
 */
function articleRange(string $documentId, ?string $nodeId, string $numero, int $ordre): Article
{
    $article = Article::create([
        'document_id' => $documentId,
        'parent_node_id' => $nodeId,
        'numero_article' => $numero,
        'ordre_affichage' => $ordre,
        'validation_status' => 'pending',
    ]);
    ArticleVersion::factory()->create(['article_id' => $article->id]);

    return $article;
}

function numerosDeLArbre(array $donnees): array
{
    $numeros = [];
    foreach ($donnees as $item) {
        if (($item['type'] ?? null) === 'ARTICLE') {
            $numeros[] = $item['number'];

            continue;
        }
        foreach ($item['articles'] ?? [] as $article) {
            $numeros[] = $article['number'];
        }
    }

    return $numeros;
}

it('trie les articles d\'une division par rang, pas par numéro lexicographique', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create(['document_id' => $document->id, 'sort_order' => 0]);

    // Il faut dépasser la douzaine d'articles : sans tri explicite, Postgres
    // servait la division via l'index unique (document_id, numero_article) et
    // rendait « 1, 10, 11, … 2, 20 … » au lieu de l'ordre de lecture.
    // Le n° 20 est inséré en dernier : il simule un article récupéré après coup.
    foreach (range(1, 40) as $i) {
        if ($i !== 20) {
            articleRange($document->id, $node->id, (string) $i, $i);
        }
    }
    articleRange($document->id, $node->id, '20', 20);

    $reponse = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertOk();

    expect(numerosDeLArbre($reponse->json('data')))
        ->toBe(array_map('strval', range(1, 40)));
});

it('garde un article à son rang après correction de son contenu', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create(['document_id' => $document->id, 'sort_order' => 0]);

    articleRange($document->id, $node->id, '1', 1);
    $deuxieme = articleRange($document->id, $node->id, '2', 2);
    articleRange($document->id, $node->id, '3', 3);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/articles/{$deuxieme->id}", ['content' => 'Texte corrigé.'])
        ->assertOk();

    $reponse = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertOk();

    expect(numerosDeLArbre($reponse->json('data')))->toBe(['1', '2', '3']);
});

it('crée un article sur un acte court, sans division', function () {
    $document = LegalDocument::factory()->create();
    articleRange($document->id, null, 'premier', 1);

    $this->actingAs($this->editor)
        ->postJson('/api/v1/articles', [
            'document_id' => $document->id,
            'numero_article' => '2',
            'content' => 'Le présent arrêté sera publié au Journal officiel.',
            'ordre_affichage' => 2,
        ])
        ->assertCreated();

    expect(Article::where('document_id', $document->id)->where('numero_article', '2')->first())
        ->parent_node_id->toBeNull();
});

it('décale la fratrie orpheline pour insérer un article à son rang', function () {
    $document = LegalDocument::factory()->create();
    articleRange($document->id, null, 'premier', 1);
    articleRange($document->id, null, '3', 2);

    // L'article 2 manquait : il doit s'insérer au rang 2 et repousser l'article 3.
    $this->actingAs($this->editor)
        ->postJson('/api/v1/articles', [
            'document_id' => $document->id,
            'numero_article' => '2',
            'content' => 'Article récupéré.',
            'ordre_affichage' => 2,
        ])
        ->assertCreated();

    $reponse = $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/tree")
        ->assertOk();

    expect(numerosDeLArbre($reponse->json('data')))->toBe(['premier', '2', '3']);
});
