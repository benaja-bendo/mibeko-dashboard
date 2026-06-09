<?php

use App\Jobs\EmbedArticleChunkJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Crée un document avec N articles (version active), embarqués ou non.
 */
function documentWithArticles(int $count, bool $embedded = false): LegalDocument
{
    $document = LegalDocument::factory()->create();

    foreach (range(1, $count) as $i) {
        $article = Article::factory()->create([
            'document_id' => $document->id,
            'numero_article' => (string) $i,
        ]);

        ArticleVersion::factory()->create([
            'article_id' => $article->id,
            'contenu_texte' => "Contenu de l'article {$i}.",
            'validity_period' => '[2020-01-01,)',
            'embedding' => $embedded ? array_fill(0, 1024, 0.1) : null,
        ]);
    }

    return $document;
}

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Role::findOrCreate('editor');
    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('lance un lot d’indexation pour les articles non vectorisés', function () {
    Bus::fake();
    $document = documentWithArticles(3);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/embed")
        ->assertOk()
        ->assertJsonPath('data.pending_count', 3)
        ->assertJsonPath('data.in_progress', true);

    Bus::assertBatched(fn ($batch) => $batch->name === "embed-doc:{$document->id}");
});

it('ne lance rien quand tous les articles sont déjà indexés', function () {
    Bus::fake();
    $document = documentWithArticles(2, embedded: true);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/embed")
        ->assertOk()
        ->assertJsonPath('data.pending_count', 0)
        ->assertJsonPath('data.in_progress', false);

    Bus::assertNothingBatched();
});

it('refuse l’indexation à un utilisateur non éditeur', function () {
    $document = documentWithArticles(1);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->postJson("/api/v1/legal-documents/{$document->id}/embed")
        ->assertForbidden();
});

it('interrompt un lot en cours et conserve le travail déjà fait', function () {
    // File « database » : les jobs sont mis en attente sans être exécutés,
    // le lot reste donc actif et annulable (en test la file par défaut est sync).
    config(['queue.default' => 'database']);

    $document = documentWithArticles(2);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$document->id}/embed")
        ->assertOk();

    $batchId = DB::table('job_batches')
        ->where('name', "embed-doc:{$document->id}")
        ->value('id');

    expect($batchId)->not->toBeNull();

    $this->actingAs($this->editor)
        ->deleteJson("/api/v1/legal-documents/{$document->id}/embed")
        ->assertOk()
        ->assertJsonPath('data.in_progress', false);

    expect(DB::table('job_batches')->where('id', $batchId)->whereNotNull('cancelled_at')->exists())->toBeTrue();
});

it('expose les compteurs d’embedding et le statut dans le catalogue', function () {
    documentWithArticles(2, embedded: true);

    $this->getJson('/api/v1/legal-documents')
        ->assertOk()
        ->assertJsonPath('data.0.articles_count', 2)
        ->assertJsonPath('data.0.embedded_articles_count', 2)
        ->assertJsonPath('data.0.embedding_in_progress', false);
});

it('vectorise les articles quand le job s’exécute', function () {
    $document = documentWithArticles(3);
    $versionIds = ArticleVersion::whereNull('embedding')->pluck('id')->all();

    (new EmbedArticleChunkJob($versionIds))->handle();

    expect(ArticleVersion::whereNull('embedding')->count())->toBe(0);
});
