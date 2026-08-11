<?php

use App\Jobs\GenerateDocumentExportPdfJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\MediaFile;
use App\Models\StructureNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Queue::fake();
    Role::findOrCreate('admin');
    Role::findOrCreate('editor');
    Role::findOrCreate('user_pro');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
    $this->pro = User::factory()->create();
    $this->pro->assignRole('user_pro');

    $this->document = LegalDocument::factory()->create([
        'titre_officiel' => 'Code de test',
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
        'document_role' => 'STOCK',
        'stock_code' => 'code-test',
        'consolidation_as_of' => '2012-06-03',
        'official_journal_id' => null,
    ]);
    $this->sha256 = str_repeat('a', 64);
    MediaFile::create([
        'document_id' => $this->document->id,
        'file_path' => 'documents/code-test/source.pdf',
        'storage_provider' => 'MINIO',
        'bucket_name' => 'mibeko-documents',
        'object_key' => 'documents/code-test/source.pdf',
        'original_filename' => 'source.pdf',
        'mime_type' => 'application/pdf',
        'file_category' => 'SOURCE_PDF',
        'file_size' => 1234,
        'checksum_sha256' => $this->sha256,
    ]);

    $oldRootId = (string) Str::uuid();
    $oldChildId = (string) Str::uuid();
    $oldRootPath = str_replace('-', '_', $oldRootId);
    $this->oldRoot = StructureNode::create([
        'id' => $oldRootId,
        'document_id' => $this->document->id,
        'type_unite' => 'LIVRE',
        'numero' => 'I',
        'titre' => 'Ancien livre',
        'tree_path' => $oldRootPath,
        'sort_order' => 0,
        'validation_status' => 'pending',
    ]);
    $this->oldChild = StructureNode::create([
        'id' => $oldChildId,
        'document_id' => $this->document->id,
        'type_unite' => 'TITRE',
        'numero' => 'I',
        'titre' => 'Ancien titre',
        'tree_path' => $oldRootPath.'.'.str_replace('-', '_', $oldChildId),
        'sort_order' => 1,
        'validation_status' => 'pending',
    ]);

    $makeArticle = function (string $number, string $content, int $order): Article {
        $article = Article::create([
            'document_id' => $this->document->id,
            'parent_node_id' => $this->oldChild->id,
            'numero_article' => $number,
            'ordre_affichage' => $order,
            'validation_status' => 'pending',
        ]);
        $article->versions()->create([
            'contenu_texte' => $content,
            'source_locator' => ['page' => $order],
            'source_media_file_id' => MediaFile::where('document_id', $this->document->id)->value('id'),
            'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
            'validation_status' => 'pending',
            'is_verified' => false,
        ]);

        return $article;
    };

    $this->article1 = $makeArticle('1', 'Texte erroné avec retour\nphysique.', 2);
    $this->article2 = $makeArticle('2', 'Texte déjà correct.', 3);
    $this->fakeArticle = $makeArticle('12    p', '12', 4);

    $this->target = [
        'schema_version' => 1,
        'document_id' => $this->document->id,
        'source_pdf' => ['filename' => 'source.pdf', 'sha256' => $this->sha256],
        'nodes' => [[
            'key' => 'node_1',
            'parent' => null,
            'type' => 'LIVRE',
            'number' => 'I',
            'title' => 'Livre fidèle au PDF',
            'order' => 0,
        ]],
        'articles' => [
            [
                'number' => '1',
                'parent' => 'node_1',
                'order' => 1,
                'page' => 9,
                'page_end' => 10,
                'content' => 'Texte juridique complet et propre.',
            ],
            [
                'number' => '2',
                'parent' => 'node_1',
                'order' => 2,
                'source_locator' => ['page' => 3],
                'content' => 'Texte déjà correct.',
            ],
        ],
    ];
});

function snapshotPublishedExtraction(object $test): array
{
    return $test->actingAs($test->admin)
        ->getJson("/api/v1/admin/legal-documents/{$test->document->id}/extraction-snapshot")
        ->assertOk()
        ->json('data');
}

function repairPayload(object $test, string $fingerprint, bool $execute, ?array $target = null): array
{
    return [
        'execute' => $execute,
        'expected_fingerprint' => $fingerprint,
        'motif' => 'Reconstruction contrôlée contre le PDF source officiel.',
        'target' => $target ?? $test->target,
    ];
}

it('réserve le snapshot et la réparation aux administrateurs', function () {
    $url = "/api/v1/admin/legal-documents/{$this->document->id}/extraction-snapshot";

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs($this->pro)->getJson($url)->assertForbidden();
    $this->actingAs($this->editor)->getJson($url)->assertForbidden();
});

it('produit un dry-run exact sans aucune écriture', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $beforeUpdatedAt = $this->document->fresh()->updated_at;

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/admin/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], false),
        )
        ->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.already_applied', false)
        ->assertJsonPath('data.plan.nodes_soft_deleted', 2)
        ->assertJsonPath('data.plan.nodes_target', 1)
        ->assertJsonPath('data.plan.articles_soft_deleted', 1)
        ->assertJsonPath('data.plan.article_contents_updated', 1)
        ->assertJsonPath('data.plan.article_locators_updated', 1);

    expect(StructureNode::where('document_id', $this->document->id)->count())->toBe(2)
        ->and(Article::where('document_id', $this->document->id)->count())->toBe(3)
        ->and($this->document->fresh()->updated_at->equalTo($beforeUpdatedAt))->toBeTrue();
});

it('remplace atomiquement l extraction publiée et conserve les identités et la version juridique', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $versionBefore = $this->article1->activeVersion()->firstOrFail();
    $validityBefore = $versionBefore->validity_period;

    $response = $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/admin/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true),
        )
        ->assertOk()
        ->assertJsonPath('data.executed', true)
        ->assertJsonPath('data.actual.nodes_created', 1)
        ->assertJsonPath('data.actual.nodes_soft_deleted', 2)
        ->assertJsonPath('data.actual.articles_soft_deleted', 1)
        ->assertJsonPath('data.actual.article_contents_updated', 1)
        ->assertJsonPath('data.actual.article_locators_updated', 1);

    $newNode = StructureNode::where('document_id', $this->document->id)->sole();
    $article1 = Article::findOrFail($this->article1->id);
    $versionAfter = $article1->activeVersion()->firstOrFail();

    expect($newNode->titre)->toBe('Livre fidèle au PDF')
        ->and($article1->parent_node_id)->toBe($newNode->id)
        ->and($article1->ordre_affichage)->toBe(1)
        ->and($versionAfter->id)->toBe($versionBefore->id)
        ->and($versionAfter->validity_period)->toBe($validityBefore)
        ->and($versionAfter->contenu_texte)->toBe('Texte juridique complet et propre.')
        ->and($versionAfter->source_locator)->toBe(['page' => 9, 'page_end' => 10])
        ->and($this->document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED)
        ->and($this->document->fresh()->metadata['extraction_repairs'])->toHaveCount(1);

    expect(StructureNode::onlyTrashed()->whereIn('id', [$this->oldRoot->id, $this->oldChild->id])->count())->toBe(2)
        ->and(Article::onlyTrashed()->find($this->fakeArticle->id))->not->toBeNull();

    expect($response->json('data.after_fingerprint'))->toMatch('/^[a-f0-9]{64}$/');
    Queue::assertPushed(GenerateDocumentExportPdfJob::class);
});

it('réutilise le snapshot comme retour arrière complet', function () {
    $originalSnapshot = snapshotPublishedExtraction($this);
    $execute = $this->actingAs($this->admin)->postJson(
        "/api/v1/admin/legal-documents/{$this->document->id}/replace-extraction",
        repairPayload($this, $originalSnapshot['expected_fingerprint'], true),
    )->assertOk();
    $newNodeId = StructureNode::where('document_id', $this->document->id)->sole()->id;

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/admin/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload(
                $this,
                $execute->json('data.after_fingerprint'),
                true,
                $originalSnapshot['target'],
            ),
        )
        ->assertOk()
        ->assertJsonPath('data.executed', true)
        ->assertJsonPath('data.actual.nodes_restored', 2)
        ->assertJsonPath('data.actual.articles_restored', 1);

    expect(StructureNode::whereIn('id', [$this->oldRoot->id, $this->oldChild->id])->count())->toBe(2)
        ->and(StructureNode::onlyTrashed()->find($newNodeId))->not->toBeNull()
        ->and(Article::find($this->fakeArticle->id))->not->toBeNull()
        ->and($this->article1->fresh()->activeVersion()->first()->contenu_texte)
        ->toBe('Texte erroné avec retour\nphysique.')
        ->and($this->document->fresh()->metadata['extraction_repairs'])->toHaveCount(2);
});

it('annule avant écriture si l empreinte préparatoire ne correspond plus', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $this->article1->activeVersion()->first()->update(['contenu_texte' => 'Modification concurrente']);

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/admin/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true),
        )
        ->assertConflict();

    expect(StructureNode::where('document_id', $this->document->id)->count())->toBe(2)
        ->and(Article::where('document_id', $this->document->id)->count())->toBe(3)
        ->and($this->article1->fresh()->activeVersion()->first()->contenu_texte)->toBe('Modification concurrente');
});

it('soft-delete désormais une branche de structure et ses articles', function () {
    $this->actingAs($this->editor)
        ->deleteJson("/api/v1/structure-nodes/{$this->oldRoot->id}")
        ->assertOk();

    expect(StructureNode::where('document_id', $this->document->id)->count())->toBe(0)
        ->and(StructureNode::onlyTrashed()->where('document_id', $this->document->id)->count())->toBe(2)
        ->and(Article::where('document_id', $this->document->id)->count())->toBe(0)
        ->and(Article::onlyTrashed()->where('document_id', $this->document->id)->count())->toBe(3)
        ->and(ArticleVersion::whereIn('article_id', [$this->article1->id, $this->article2->id, $this->fakeArticle->id])->count())->toBe(3);
});
