<?php

use App\Ai\AnomalyDetector;
use App\Mcp\Servers\MibekoServer;
use App\Mcp\Tools\DetectAnomaliesTool;
use App\Mcp\Tools\GetArticleTool;
use App\Mcp\Tools\ListCurationFlagsTool;
use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Crée un éditeur disposant de la permission de curation (pour les outils MCP). */
function mcpEditor(): User
{
    Permission::findOrCreate('documents.update');
    $role = Role::findOrCreate('editor');
    $role->givePermissionTo('documents.update');
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    return $editor;
}

/** Crée une version courante (période ouverte) pour un article (tests MCP). */
function mcpVersion($article, string $content, array $locator = []): ArticleVersion
{
    return $article->versions()->create([
        'contenu_texte' => $content,
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
        'source_locator' => $locator,
    ]);
}

it('runs structural detection for an editor and reports open flags', function () {
    $editor = mcpEditor();
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Dispositions', 'tree_path' => 'n1',
    ]);
    $article = $document->articles()->create([
        'numero_article' => '1', 'ordre_affichage' => 1, 'parent_node_id' => $node->id,
    ]);
    mcpVersion($article, '   '); // blanc → article_vide bloquant

    $response = MibekoServer::actingAs($editor)
        ->tool(DetectAnomaliesTool::class, ['document_id' => $document->id]);

    $response->assertOk()->assertStructuredContent([
        'document_id' => $document->id,
        'structural_created' => 1,
        'ai_found' => null,
        'open_flags' => ['blocking' => 1, 'warning' => 0, 'info' => 0],
    ]);

    expect(CurationFlag::where('document_id', $document->id)
        ->where('source', CurationFlag::SOURCE_STRUCTURAL)
        ->where('type_probleme', 'article_vide')
        ->exists())->toBeTrue();
});

it('runs the semantic ai layer when include_ai is requested', function () {
    $editor = mcpEditor();
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Titre', 'tree_path' => 'n1',
    ]);
    $article = $document->articles()->create([
        'numero_article' => '1', 'ordre_affichage' => 1, 'parent_node_id' => $node->id,
    ]);
    mcpVersion($article, 'court', ['page' => 4]); // < 40 caractères → feuille suspecte

    AnomalyDetector::fake([
        ['anomalies' => [[
            'ref' => $article->id,
            'type_probleme' => 'contenu_tronque',
            'severity' => 'blocking',
            'description' => 'Texte tronqué.',
            'suggestion' => null,
            'confidence' => 0.9,
        ]]],
    ]);

    $response = MibekoServer::actingAs($editor)
        ->tool(DetectAnomaliesTool::class, ['document_id' => $document->id, 'include_ai' => true]);

    $response->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('ai_found', 1)
        ->where('open_flags.blocking', 1)
        ->etc());

    expect(CurationFlag::where('document_id', $document->id)->where('source', CurationFlag::SOURCE_LLM)->count())->toBe(1);
});

it('denies detection to users without the curation permission', function () {
    $intruder = User::factory()->create();
    $document = LegalDocument::factory()->create();

    MibekoServer::actingAs($intruder)
        ->tool(DetectAnomaliesTool::class, ['document_id' => $document->id])
        ->assertHasErrors(['réservé aux éditeurs']);

    // Anti-oracle : même refus pour un UUID inexistant — la réponse ne doit
    // pas révéler l'existence d'un document non publié à un non-éditeur.
    MibekoServer::actingAs($intruder)
        ->tool(DetectAnomaliesTool::class, ['document_id' => (string) Str::uuid()])
        ->assertHasErrors(['réservé aux éditeurs']);

    expect(CurationFlag::where('document_id', $document->id)->count())->toBe(0);
});

it('denies flag listing to users without the curation permission', function () {
    $intruder = User::factory()->create();
    $document = LegalDocument::factory()->create();
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'llm',
        'type_probleme' => 'charabia_ocr', 'severity' => 'blocking',
        'suggestion' => ['text' => 'Contenu non publié.'], 'resolved' => false,
    ]);

    MibekoServer::actingAs($intruder)
        ->tool(ListCurationFlagsTool::class, ['document_id' => $document->id])
        ->assertHasErrors(['réservé aux éditeurs'])
        ->assertDontSee('Contenu non publié');
});

it('allows curation tools on the local transport without a user', function () {
    // Mcp::local (stdio) n'a pas d'utilisateur : même niveau de confiance
    // qu'un shell local. Le web, lui, est derrière auth:sanctum.
    $document = LegalDocument::factory()->create();
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => false,
    ]);

    MibekoServer::tool(ListCurationFlagsTool::class, ['document_id' => $document->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('total', 1)
            ->etc());
});

it('does not trigger the ai layer when include_ai is the string "false"', function () {
    $editor = mcpEditor();
    $document = LegalDocument::factory()->create();
    // Filet : si la couche IA se déclenchait par erreur, le fake éviterait un
    // appel réseau réel et ai_found vaudrait 0 au lieu de null.
    AnomalyDetector::fake([[]]);

    MibekoServer::actingAs($editor)
        ->tool(DetectAnomaliesTool::class, ['document_id' => $document->id, 'include_ai' => 'false'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('ai_found', null)
            ->etc());
});

it('rate limits the ai analysis per user', function () {
    $editor = mcpEditor();
    $document = LegalDocument::factory()->create();

    foreach (range(1, 5) as $i) {
        RateLimiter::hit("mcp-ai-analysis:{$editor->id}", 60);
    }

    MibekoServer::actingAs($editor)
        ->tool(DetectAnomaliesTool::class, ['document_id' => $document->id, 'include_ai' => true])
        ->assertHasErrors(["Limite d'analyses IA atteinte"]);
});

it('rejects malformed and unknown document ids', function () {
    $editor = mcpEditor();

    MibekoServer::actingAs($editor)
        ->tool(DetectAnomaliesTool::class, ['document_id' => 'pas-un-uuid'])
        ->assertHasErrors(['UUID']);

    MibekoServer::actingAs($editor)
        ->tool(DetectAnomaliesTool::class, ['document_id' => (string) Str::uuid()])
        ->assertHasErrors(['introuvable']);
});

it('lists open curation flags blocking-first with their suggestion', function () {
    $editor = mcpEditor();
    $document = LegalDocument::factory()->create();

    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'division_vide', 'severity' => 'warning', 'resolved' => false,
    ]);
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'llm',
        'type_probleme' => 'charabia_ocr', 'severity' => 'blocking',
        'description' => 'Texte illisible.', 'suggestion' => ['text' => 'Version corrigée.'],
        'confidence' => 0.8, 'resolved' => false,
    ]);
    // Résolue : exclue par le filtre open_only par défaut.
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => true,
    ]);

    $response = MibekoServer::actingAs($editor)
        ->tool(ListCurationFlagsTool::class, ['document_id' => $document->id]);

    $response->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('document_id', $document->id)
        ->where('total', 2)
        ->count('flags', 2)
        ->where('flags.0.severity', 'blocking')
        ->where('flags.0.suggestion.text', 'Version corrigée.')
        ->where('flags.1.severity', 'warning')
        ->etc());
});

it('includes resolved flags when open_only is false', function () {
    $editor = mcpEditor();
    $document = LegalDocument::factory()->create();
    CurationFlag::create([
        'document_id' => $document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => true,
    ]);

    MibekoServer::actingAs($editor)
        ->tool(ListCurationFlagsTool::class, ['document_id' => $document->id, 'open_only' => false])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('total', 1)
            ->where('flags.0.resolved', true)
            ->etc());
});

it('returns the article content, breadcrumb and open flags for curation', function () {
    $editor = mcpEditor();
    $document = LegalDocument::factory()->create();
    $node = StructureNode::factory()->create([
        'document_id' => $document->id, 'numero' => 'I', 'titre' => 'Des personnes', 'tree_path' => 'n1',
    ]);
    $article = $document->articles()->create([
        'numero_article' => '7', 'ordre_affichage' => 1, 'parent_node_id' => $node->id,
    ]);
    mcpVersion($article, 'Tout Congolais jouit des droits civils.', ['page' => 12]);

    $flag = CurationFlag::create([
        'document_id' => $document->id, 'article_id' => $article->id, 'source' => 'llm',
        'type_probleme' => 'charabia_ocr', 'severity' => 'warning',
        'description' => 'Passage suspect.', 'suggestion' => ['text' => 'Correction proposée.'],
        'confidence' => 0.7, 'resolved' => false,
    ]);

    $response = MibekoServer::actingAs($editor)
        ->tool(GetArticleTool::class, ['article_id' => $article->id]);

    $response->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('id', $article->id)
        ->where('numero_article', '7')
        ->where('contenu_texte', 'Tout Congolais jouit des droits civils.')
        ->where('breadcrumb', fn (string $breadcrumb) => str_contains($breadcrumb, 'Des personnes'))
        ->where('version.page', 12)
        ->count('open_flags', 1)
        ->where('open_flags.0.id', $flag->id)
        ->where('open_flags.0.suggestion.text', 'Correction proposée.')
        ->etc());
});

it('denies article reading to users without the curation permission', function () {
    $intruder = User::factory()->create();
    $document = LegalDocument::factory()->create();
    $article = $document->articles()->create(['numero_article' => '1', 'ordre_affichage' => 1]);
    mcpVersion($article, 'Contenu non publié.');

    MibekoServer::actingAs($intruder)
        ->tool(GetArticleTool::class, ['article_id' => $article->id])
        ->assertHasErrors(['réservé aux éditeurs']);
});

it('treats a soft-deleted article as not found', function () {
    $editor = mcpEditor();
    $document = LegalDocument::factory()->create();
    $article = $document->articles()->create(['numero_article' => '1', 'ordre_affichage' => 1]);
    $article->delete();

    MibekoServer::actingAs($editor)
        ->tool(GetArticleTool::class, ['article_id' => $article->id])
        ->assertHasErrors(['introuvable']);
});

it('exposes the curation tools on the authenticated web transport', function () {
    Sanctum::actingAs(mcpEditor());

    $response = $this->postJson('/mcp/mibeko', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    $response->assertStatus(200);
    $content = $response->getContent();

    expect($content)->toContain('detect-anomalies-tool')
        ->toContain('list-curation-flags-tool')
        ->toContain('get-article-tool')
        ->toContain('search-legal-database-tool');
});

it('rejects unauthenticated access to the web mcp endpoint', function () {
    $this->postJson('/mcp/mibeko', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertStatus(401);
});
