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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
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
    Permission::findOrCreate('documents.update');
    $this->admin->givePermissionTo('documents.update');
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
        ->getJson("/api/v1/legal-documents/{$test->document->id}/extraction-snapshot")
        ->assertOk()
        ->json('data');
}

function repairPayload(
    object $test,
    string $fingerprint,
    bool $execute,
    ?array $target = null,
    ?int $confirmDeletions = null,
): array {
    return [
        ...($confirmDeletions === null ? [] : ['confirm_deletions' => $confirmDeletions]),
        'execute' => $execute,
        'expected_fingerprint' => $fingerprint,
        'motif' => 'Reconstruction contrôlée contre le PDF source officiel.',
        'target' => $target ?? $test->target,
    ];
}

it('réserve à l administrateur un document déjà publié, et ferme le canal aux non éditeurs', function () {
    $url = "/api/v1/legal-documents/{$this->document->id}/extraction-snapshot";

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs($this->pro)->getJson($url)->assertForbidden();
    $this->actingAs($this->editor)->getJson($url)->assertForbidden();
});

it('produit un dry-run exact sans aucune écriture', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $beforeUpdatedAt = $this->document->fresh()->updated_at;

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
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
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1),
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

it('répare un document temporairement retiré sans le republier implicitement', function () {
    $snapshot = snapshotPublishedExtraction($this);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/legal-documents/{$this->document->id}", [
            'curation_status' => LegalDocument::STATUS_REVIEW,
            'motif' => 'Retrait public provisoire avant reconstruction de l’extraction.',
        ])
        ->assertOk()
        ->assertJsonPath('data.curation_status', LegalDocument::STATUS_REVIEW);

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1),
        )
        ->assertOk()
        ->assertJsonPath('data.executed', true)
        ->assertJsonPath('data.curation_status', LegalDocument::STATUS_REVIEW);

    expect($this->document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW)
        ->and($this->document->hasEverBeenPublished())->toBeTrue();
    Queue::assertNotPushed(GenerateDocumentExportPdfJob::class);
});

function demoteToNeverPublished(object $test, string $status): void
{
    $test->document->audits()->delete();
    DB::table('legal_documents')
        ->where('id', $test->document->id)
        ->update(['curation_status' => $status]);
    $test->document->refresh();

    expect($test->document->hasEverBeenPublished())->toBeFalse();
}

it('ouvre le canal à un éditeur sur un document jamais publié', function () {
    $snapshot = snapshotPublishedExtraction($this);
    demoteToNeverPublished($this, LegalDocument::STATUS_DRAFT);

    $url = "/api/v1/legal-documents/{$this->document->id}";

    $this->actingAs($this->editor)->getJson("{$url}/extraction-snapshot")->assertOk();

    $this->actingAs($this->editor)
        ->postJson(
            "{$url}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1),
        )
        ->assertOk()
        ->assertJsonPath('data.executed', true);

    // Un brouillon ne se fait pas promouvoir en revue par un import.
    expect($this->document->fresh()->curation_status)->toBe(LegalDocument::STATUS_DRAFT);
});

it('ramène en revue un document validé et remet en attente les articles corrigés', function () {
    $snapshot = snapshotPublishedExtraction($this);
    demoteToNeverPublished($this, LegalDocument::STATUS_VALIDATED);

    DB::table('articles')->where('document_id', $this->document->id)->update(['validation_status' => 'validated']);
    DB::table('article_versions')
        ->whereIn('article_id', [$this->article1->id, $this->article2->id])
        ->update(['validation_status' => 'validated']);

    $this->actingAs($this->editor)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1),
        )
        ->assertOk();

    $article1 = Article::findOrFail($this->article1->id);
    $article2 = Article::findOrFail($this->article2->id);

    expect($this->document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW)
        // L'article 1 change de texte : il repart en attente de relecture.
        ->and($article1->validation_status)->toBe('pending')
        ->and($article1->activeVersion()->firstOrFail()->validation_status)->toBe('pending')
        // L'article 2 est identique dans la cible : rien ne justifie de le déclasser.
        ->and($article2->validation_status)->toBe('validated');
});

it('laisse intact le statut de validation des articles d un document publié', function () {
    $snapshot = snapshotPublishedExtraction($this);
    DB::table('articles')->where('document_id', $this->document->id)->update(['validation_status' => 'validated']);

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1),
        )
        ->assertOk();

    // `validation_status` conditionne la visibilité publique : un remplacement
    // d'extraction ne doit pas faire disparaître un article du corpus publié.
    expect(Article::findOrFail($this->article1->id)->validation_status)->toBe('validated')
        ->and($this->document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('valide la cible figée de reconstruction de l arrêté 3277', function () {
    $planPath = storage_path('app/corrections/2026-08-18-reconstruire-arrete-3277.json');
    $plan = json_decode(file_get_contents($planPath), true, flags: JSON_THROW_ON_ERROR);
    $target = $plan['target'];
    $target['document_id'] = $this->document->id;
    $target['source_pdf']['sha256'] = $this->sha256;
    $snapshot = snapshotPublishedExtraction($this);

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], false, $target),
        )
        ->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.plan.nodes_target', 19)
        ->assertJsonPath('data.plan.target_articles', 72)
        ->assertJsonPath('data.plan.articles_added_or_restored', 71);
});

it('réutilise le snapshot comme retour arrière complet', function () {
    $originalSnapshot = snapshotPublishedExtraction($this);
    $execute = $this->actingAs($this->admin)->postJson(
        "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
        repairPayload($this, $originalSnapshot['expected_fingerprint'], true, null, 1),
    )->assertOk();
    $newNodeId = StructureNode::where('document_id', $this->document->id)->sole()->id;

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
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
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1),
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

it('annonce une renumérotation à identifiant conservé comme une correction, jamais comme une suppression', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $target = $snapshot['target'];

    // Cas réel : un numéro mal océrisé est corrigé et une division retitrée,
    // les deux en conservant leur identifiant. Rien n'est retiré du document.
    $target['articles'] = array_map(function (array $article): array {
        if ($article['number'] === '1') {
            $article['number'] = '1 bis';
        }

        return $article;
    }, $target['articles']);
    $target['nodes'][0]['title'] = 'Livre fidèle au PDF';

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], false, $target),
        )
        ->assertOk()
        ->assertJsonPath('data.plan.nodes_soft_deleted', 0)
        ->assertJsonPath('data.plan.articles_soft_deleted', 0)
        ->assertJsonPath('data.plan.articles_added_or_restored', 0)
        ->assertJsonPath('data.plan.article_contents_updated', 0)
        ->assertJsonPath('data.plan.article_locators_updated', 0);
});

it('annonce dans le plan exactement ce que l exécution réalise', function () {
    $snapshot = snapshotPublishedExtraction($this);

    $response = $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1),
        )
        ->assertOk();

    $plan = $response->json('data.plan');
    $actual = $response->json('data.actual');

    expect($actual['nodes_soft_deleted'])->toBe($plan['nodes_soft_deleted'])
        ->and($actual['articles_soft_deleted'])->toBe($plan['articles_soft_deleted'])
        ->and($actual['article_contents_updated'])->toBe($plan['article_contents_updated'])
        ->and($actual['article_locators_updated'])->toBe($plan['article_locators_updated'])
        ->and($actual['articles_created'] + $actual['articles_restored'])
        ->toBe($plan['articles_added_or_restored']);
});

function renameArticles(array $target, array $renames): array
{
    $target['articles'] = array_map(function (array $article) use ($renames): array {
        $article['number'] = $renames[$article['number']] ?? $article['number'];

        return $article;
    }, $target['articles']);

    return $target;
}

it('applique une renumérotation en conservant identité, version et validité', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $versionBefore = $this->article1->activeVersion()->firstOrFail();

    // La cible reprend le snapshot : elle ne retire rien, aucune confirmation
    // de suppression n'est donc requise.
    $target = renameArticles($snapshot['target'], ['1' => '1 bis']);

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, $target),
        )
        ->assertOk()
        ->assertJsonPath('data.executed', true)
        ->assertJsonPath('data.actual.articles_soft_deleted', 0)
        ->assertJsonPath('data.actual.articles_created', 0);

    $article1 = Article::findOrFail($this->article1->id);
    $versionAfter = $article1->activeVersion()->firstOrFail();

    expect($article1->numero_article)->toBe('1 bis')
        ->and($article1->deleted_at)->toBeNull()
        ->and($versionAfter->id)->toBe($versionBefore->id)
        ->and($versionAfter->validity_period)->toBe($versionBefore->validity_period)
        ->and($versionAfter->contenu_texte)->toBe($versionBefore->contenu_texte)
        ->and(Article::onlyTrashed()->where('document_id', $this->document->id)->count())->toBe(0);
});

it('applique une permutation de deux numéros sans violer l index unique', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $version1Before = $this->article1->activeVersion()->firstOrFail();
    $version2Before = $this->article2->activeVersion()->firstOrFail();

    // Le cas que l'index unique partiel interdit en écriture naïve : chacun des
    // deux numéros est occupé par l'autre article au moment de l'attribution.
    $target = renameArticles($snapshot['target'], ['1' => '2', '2' => '1']);

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, $target),
        )
        ->assertOk()
        ->assertJsonPath('data.actual.articles_created', 0)
        ->assertJsonPath('data.actual.articles_soft_deleted', 0);

    $article1 = Article::findOrFail($this->article1->id);
    $article2 = Article::findOrFail($this->article2->id);

    expect($article1->numero_article)->toBe('2')
        ->and($article2->numero_article)->toBe('1')
        ->and($article1->activeVersion()->firstOrFail()->id)->toBe($version1Before->id)
        ->and($article2->activeVersion()->firstOrFail()->id)->toBe($version2Before->id)
        // Aucun numéro de garage ne doit survivre à la transaction.
        ->and(Article::where('document_id', $this->document->id)
            ->where('numero_article', 'like', '~parked~%')->count())->toBe(0);
});

it('bloque une cible qui retire des articles tant que le nombre n est pas confirmé', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $url = "/api/v1/legal-documents/{$this->document->id}/replace-extraction";

    // La cible par défaut abandonne le faux article : c'est un retrait.
    $this->actingAs($this->admin)
        ->postJson($url, repairPayload($this, $snapshot['expected_fingerprint'], false))
        ->assertOk()
        ->assertJsonPath('data.plan.articles_soft_deleted', 1);

    $this->actingAs($this->admin)
        ->postJson($url, repairPayload($this, $snapshot['expected_fingerprint'], true))
        ->assertConflict();

    // Un nombre confirmé qui ne correspond pas ne vaut pas confirmation.
    $this->actingAs($this->admin)
        ->postJson($url, [...repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1), 'confirm_deletions' => 3])
        ->assertConflict();

    expect(Article::onlyTrashed()->where('document_id', $this->document->id)->count())->toBe(0);

    $this->actingAs($this->admin)
        ->postJson($url, [...repairPayload($this, $snapshot['expected_fingerprint'], true, null, 1), 'confirm_deletions' => 1])
        ->assertOk()
        ->assertJsonPath('data.actual.articles_soft_deleted', 1);
});

it('applique sans confirmation une cible qui ne retire rien', function () {
    $snapshot = snapshotPublishedExtraction($this);
    $target = $snapshot['target'];
    $target['articles'][0]['content'] = 'Texte juridique complet et propre, relu contre le PDF.';

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, $target),
        )
        ->assertOk()
        ->assertJsonPath('data.actual.articles_soft_deleted', 0);
});

it('signale un article vidé ou raccourci de moitié sans bloquer', function () {
    // Un article long, pour dépasser le plancher sous lequel une proportion ne
    // veut rien dire.
    $long = str_repeat('Disposition juridique de référence. ', 20);
    DB::table('article_versions')
        ->where('article_id', $this->article2->id)
        ->update(['contenu_texte' => $long]);

    $snapshot = snapshotPublishedExtraction($this);
    $target = $snapshot['target'];
    $target['articles'] = array_map(function (array $article) use ($long): array {
        if ($article['number'] === '2') {
            $article['content'] = mb_substr($long, 0, 100);
        }

        return $article;
    }, $target['articles']);

    $response = $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], false, $target),
        )
        ->assertOk();

    $warnings = collect($response->json('data.warnings'));

    expect($warnings)->toHaveCount(1)
        ->and($warnings->first()['number'])->toBe('2')
        ->and($warnings->first()['kind'])->toBe('contenu_raccourci')
        ->and($warnings->first()['characters_after'])->toBe(100);
});

it('restaure un article dont l ancien numéro est déjà porté par un article vivant', function () {
    // L'index unique partiel ne couvre que les lignes vivantes : un article en
    // corbeille peut donc porter le même numéro qu'un article vivant. Le sortir
    // de corbeille avec son ancien numéro violerait l'index — il doit recevoir
    // son numéro cible dans la même écriture.
    $trashed = Article::create([
        'document_id' => $this->document->id,
        'parent_node_id' => $this->oldChild->id,
        'numero_article' => '2 provisoire',
        'ordre_affichage' => 5,
        'validation_status' => 'pending',
    ]);
    $trashed->versions()->create([
        'contenu_texte' => 'Ancienne rédaction mise au rebut.',
        'source_locator' => ['page' => 5],
        'source_media_file_id' => MediaFile::where('document_id', $this->document->id)->value('id'),
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'pending',
        'is_verified' => false,
    ]);
    // Le numéro en conflit ne peut être posé qu'une fois l'article en corbeille :
    // vivant, il heurterait l'article 2 — ce que ce test veut justement mettre
    // en scène du côté de la restauration.
    $trashed->delete();
    DB::table('articles')->where('id', $trashed->id)->update(['numero_article' => '2']);

    expect(Article::where('document_id', $this->document->id)->where('numero_article', '2')->count())->toBe(1)
        ->and(Article::onlyTrashed()->find($trashed->id))->not->toBeNull();

    $snapshot = snapshotPublishedExtraction($this);
    $target = $snapshot['target'];
    $target['articles'][] = [
        'id' => $trashed->id,
        'number' => '3',
        'parent' => $target['articles'][0]['parent'],
        'order' => 5,
        'content' => 'Ancienne rédaction mise au rebut.',
        'source_locator' => ['page' => 5],
    ];

    $this->actingAs($this->admin)
        ->postJson(
            "/api/v1/legal-documents/{$this->document->id}/replace-extraction",
            repairPayload($this, $snapshot['expected_fingerprint'], true, $target),
        )
        ->assertOk()
        ->assertJsonPath('data.actual.articles_restored', 1);

    expect(Article::findOrFail($trashed->id)->numero_article)->toBe('3')
        ->and(Article::findOrFail($this->article2->id)->numero_article)->toBe('2');
});
