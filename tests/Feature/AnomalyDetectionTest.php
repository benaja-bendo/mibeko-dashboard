<?php

use App\Ai\AnomalyDetector;
use App\Jobs\DetectDocumentAnomalies;
use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\User;
use App\Services\Curation\StructuralAnomalyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Crée une version courante (période ouverte) pour un article. */
function makeVersion($article, string $content, array $locator = []): ArticleVersion
{
    return $article->versions()->create([
        'contenu_texte' => $content,
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
        'source_locator' => $locator,
    ]);
}

it('flags an article with empty content as blocking', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Dispositions', 'tree_path' => 'n1',
    ]);
    $article = $document->articles()->create([
        'numero_article' => '1', 'ordre_affichage' => 1, 'parent_node_id' => $node->id, 'validation_status' => 'pending',
    ]);
    makeVersion($article, '   '); // blanc → contenu perdu

    app(StructuralAnomalyDetector::class)->detect($document);

    $flag = CurationFlag::where('document_id', $document->id)->where('type_probleme', 'article_vide')->first();
    expect($flag)->not->toBeNull()
        ->and($flag->severity)->toBe('blocking')
        ->and($flag->source)->toBe('structural')
        ->and($flag->article_id)->toBe($article->id);
});

it('flags untitled and empty divisions as warnings', function () {
    $document = LegalDocument::factory()->create();

    // Division sans numéro ni titre, mais avec un article (donc pas « vide »).
    $untitled = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => '', 'titre' => '', 'tree_path' => 'a',
    ]);
    $art = $document->articles()->create(['numero_article' => '1', 'ordre_affichage' => 1, 'parent_node_id' => $untitled->id]);
    makeVersion($art, 'Contenu présent');

    // Division identifiée mais vide (ni article ni sous-division).
    $empty = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => '2', 'titre' => 'Chapitre vide', 'tree_path' => 'b',
    ]);

    app(StructuralAnomalyDetector::class)->detect($document);

    expect(CurationFlag::where('node_id', $untitled->id)->where('type_probleme', 'division_sans_titre')->where('severity', 'warning')->exists())->toBeTrue()
        ->and(CurationFlag::where('node_id', $empty->id)->where('type_probleme', 'division_vide')->where('severity', 'warning')->exists())->toBeTrue()
        // La division qui porte un article n'est PAS signalée « vide ».
        ->and(CurationFlag::where('node_id', $untitled->id)->where('type_probleme', 'division_vide')->exists())->toBeFalse();
});

it('flags a floating article but spares special leaves (preamble)', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Titre', 'tree_path' => 'n1',
    ]);
    $inNode = $document->articles()->create(['numero_article' => '1', 'ordre_affichage' => 1, 'parent_node_id' => $node->id]);
    makeVersion($inNode, 'ok');

    // Article régulier flottant (parent NULL) alors que le document est structuré.
    $orphan = $document->articles()->create(['numero_article' => '2', 'ordre_affichage' => 2]);
    makeVersion($orphan, 'Contenu orphelin');

    // Feuille spéciale (préambule) légitimement à la racine → ne doit PAS être signalée.
    $preamble = $document->articles()->create(['numero_article' => 'PREAMBULE', 'ordre_affichage' => 0]);
    makeVersion($preamble, 'La ministre... Vu la Constitution', ['content_format' => 'preamble']);

    app(StructuralAnomalyDetector::class)->detect($document);

    expect(CurationFlag::where('article_id', $orphan->id)->where('type_probleme', 'article_hors_structure')->exists())->toBeTrue()
        ->and(CurationFlag::where('article_id', $preamble->id)->exists())->toBeFalse();
});

it('is idempotent and preserves resolved flags', function () {
    $document = LegalDocument::factory()->create();
    StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => '1', 'titre' => 'Vide', 'tree_path' => 'b',
    ]);

    $detector = app(StructuralAnomalyDetector::class);
    $detector->detect($document);
    $detector->detect($document); // re-run : pas de doublon

    expect(CurationFlag::where('document_id', $document->id)->where('source', 'structural')->count())->toBe(1);

    // Un humain résout le flag, puis on relance : le flag résolu est conservé,
    // un nouveau non résolu est recréé (l'anomalie persiste).
    CurationFlag::where('document_id', $document->id)->update(['resolved' => true]);
    $detector->detect($document);

    expect(CurationFlag::where('document_id', $document->id)->where('resolved', true)->count())->toBe(1)
        ->and(CurationFlag::where('document_id', $document->id)->where('resolved', false)->count())->toBe(1);
});

it('surfaces anomalies as error state in the document tree', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => '1', 'titre' => 'Chap vide', 'tree_path' => 'b',
    ]);

    app(StructuralAnomalyDetector::class)->detect($document);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/tree");
    $response->assertStatus(200);

    $nodePayload = collect($response->json('data'))->firstWhere('id', $node->id);
    expect($nodePayload['validation_status'])->toBe('error')
        ->and($nodePayload['anomaly_count'])->toBe(1);
});

it('creates llm anomaly flags from the agent on suspect articles', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Titre', 'tree_path' => 'n1',
    ]);
    $article = $document->articles()->create(['numero_article' => '1', 'parent_node_id' => $node->id, 'ordre_affichage' => 1]);
    makeVersion($article, 'court', ['page' => 4]); // < 40 caractères → suspect

    AnomalyDetector::fake([
        '{"anomalies":[{"ref":"'.$article->id.'","type_probleme":"decoupe_fragment","severity":"warning","description":"Fragment d\'article.","suggestion":"Fusionner avec le precedent.","confidence":0.8}]}',
    ]);

    (new DetectDocumentAnomalies($document->id))->handle(app(AnomalyDetector::class));

    $flag = CurationFlag::where('document_id', $document->id)->where('source', 'llm')->first();
    expect($flag)->not->toBeNull()
        ->and($flag->type_probleme)->toBe('decoupe_fragment')
        ->and($flag->severity)->toBe('warning')
        ->and($flag->article_id)->toBe($article->id)
        ->and($flag->suggestion)->toBe(['text' => 'Fusionner avec le precedent.'])
        ->and($flag->anchor)->toBe(['page' => 4]);
});

it('degrades gracefully when the llm returns no usable json', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Titre', 'tree_path' => 'n1',
    ]);
    $article = $document->articles()->create(['numero_article' => '1', 'parent_node_id' => $node->id, 'ordre_affichage' => 1]);
    makeVersion($article, 'court');

    AnomalyDetector::fake(['Désolé, je ne peux pas répondre.']);

    (new DetectDocumentAnomalies($document->id))->handle(app(AnomalyDetector::class));

    expect(CurationFlag::where('document_id', $document->id)->where('source', 'llm')->count())->toBe(0);
});

it('is idempotent across llm re-runs', function () {
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Titre', 'tree_path' => 'n1',
    ]);
    $article = $document->articles()->create(['numero_article' => '1', 'parent_node_id' => $node->id, 'ordre_affichage' => 1]);
    makeVersion($article, 'court');

    $json = '{"anomalies":[{"ref":"'.$article->id.'","type_probleme":"contenu_tronque","severity":"blocking","description":"Tronqué.","suggestion":null,"confidence":0.9}]}';
    AnomalyDetector::fake([$json, $json]);

    (new DetectDocumentAnomalies($document->id))->handle(app(AnomalyDetector::class));
    (new DetectDocumentAnomalies($document->id))->handle(app(AnomalyDetector::class));

    expect(CurationFlag::where('document_id', $document->id)->where('source', 'llm')->count())->toBe(1);
});

/** Crée un éditeur disposant de la permission de curation. */
function makeEditor(): User
{
    Permission::findOrCreate('documents.update');
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('documents.update');
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    return $editor;
}

it('lists document anomalies blocking-first for an editor', function () {
    $editor = makeEditor();
    $document = LegalDocument::factory()->create();

    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'division_vide', 'severity' => 'warning', 'resolved' => false,
    ]);
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => false,
    ]);

    $response = $this->actingAs($editor)
        ->getJson("/api/v1/legal-documents/{$document->id}/curation-flags");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data')
        // Bloquante en tête.
        ->assertJsonPath('data.0.severity', 'blocking')
        ->assertJsonPath('data.1.severity', 'warning');
});

it('lets an editor resolve an anomaly and unblock publishing', function () {
    $editor = makeEditor();
    $document = LegalDocument::factory()->create();
    $flag = CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => false,
    ]);

    $this->actingAs($editor)
        ->patchJson("/api/v1/curation-flags/{$flag->id}", ['resolved' => true])
        ->assertStatus(200)
        ->assertJsonPath('data.resolved', true);

    expect($flag->fresh()->resolved)->toBeTrue()
        ->and($flag->fresh()->resolved_by)->toBe($editor->id);
});

it('lets an editor trigger structural detection from the document', function () {
    $editor = makeEditor();
    $document = LegalDocument::factory()->create();
    StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => '1', 'titre' => 'Vide', 'tree_path' => 'b',
    ]);

    $this->actingAs($editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/detect-anomalies")
        ->assertStatus(200)
        ->assertJsonPath('data.created', 1);

    expect(CurationFlag::where('document_id', $document->id)->where('source', 'structural')->count())->toBe(1);
});

it('runs the AI analysis from the document endpoint', function () {
    $editor = makeEditor();
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Titre', 'tree_path' => 'n1',
    ]);
    $article = $document->articles()->create(['numero_article' => '1', 'parent_node_id' => $node->id, 'ordre_affichage' => 1]);
    makeVersion($article, 'court'); // < 40 caractères → feuille suspecte

    AnomalyDetector::fake([
        '{"anomalies":[{"ref":"'.$article->id.'","type_probleme":"contenu_tronque","severity":"blocking","description":"Texte tronqué.","suggestion":null,"confidence":0.9}]}',
    ]);

    $this->actingAs($editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/analyze-ai")
        ->assertStatus(200)
        ->assertJsonPath('data.found', 1);

    expect(CurationFlag::where('document_id', $document->id)->where('source', 'llm')->count())->toBe(1);
});

it('blocks curation endpoints for non editors', function () {
    $intruder = User::factory()->create();
    $document = LegalDocument::factory()->create();

    $this->actingAs($intruder)
        ->getJson("/api/v1/legal-documents/{$document->id}/curation-flags")
        ->assertStatus(403);
});

it('blocks publishing on blocking flags but allows on warnings only', function () {
    Permission::findOrCreate('documents.update');
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    // Document A : anomalie BLOQUANTE → publication refusée (422).
    $blocked = LegalDocument::factory()->hasArticles(1)->create(['curation_status' => 'review']);
    CurationFlag::create([
        'document_id' => $blocked->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => false,
    ]);

    $this->actingAs($editor)->patchJson("/api/v1/legal-documents/{$blocked->id}", [
        'curation_status' => 'published',
    ])->assertStatus(422);

    // Document B : anomalie WARNING seulement → publication autorisée.
    $warned = LegalDocument::factory()->hasArticles(1)->create(['curation_status' => 'review']);
    CurationFlag::create([
        'document_id' => $warned->id, 'source' => 'structural',
        'type_probleme' => 'division_vide', 'severity' => 'warning', 'resolved' => false,
    ]);

    $this->actingAs($editor)->patchJson("/api/v1/legal-documents/{$warned->id}", [
        'curation_status' => 'published',
    ])->assertStatus(200);

    expect($warned->fresh()->curation_status)->toBe('published');
});
