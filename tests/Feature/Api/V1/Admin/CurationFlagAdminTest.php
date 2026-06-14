<?php

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Role::findOrCreate('admin');
    Role::findOrCreate('user_pro');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->proUser = User::factory()->create();
    $this->proUser->assignRole('user_pro');

    $this->document = LegalDocument::factory()->create(['titre_officiel' => 'Loi sur le travail']);
});

// ---------------------------------------------------------------------------
// Garde-fous d'accès
// ---------------------------------------------------------------------------

it('refuse la liste des signalements sans authentification', function () {
    $this->getJson('/api/v1/admin/flags')->assertUnauthorized();
});

it('refuse la liste des signalements à un non-admin', function () {
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/admin/flags')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Liste & filtres
// ---------------------------------------------------------------------------

it('ne liste que les signalements ouverts par défaut', function () {
    $open = CurationFlag::create([
        'document_id' => $this->document->id,
        'type_probleme' => 'erreur_contenu',
        'description' => 'Texte tronqué',
        'resolved' => false,
    ]);
    CurationFlag::create([
        'document_id' => $this->document->id,
        'type_probleme' => 'doublon',
        'resolved' => true,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/flags')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $open->id)
        ->assertJsonPath('data.0.target.kind', 'document')
        ->assertJsonPath('data.0.target.document_title', 'Loi sur le travail');
});

it('liste les signalements résolus avec status=resolved', function () {
    CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'erreur', 'resolved' => false]);
    CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'doublon', 'resolved' => true]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/flags?status=resolved')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.resolved', true);
});

it('liste tous les signalements avec status=all', function () {
    CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'erreur', 'resolved' => false]);
    CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'doublon', 'resolved' => true]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/flags?status=all')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filtre par type de problème', function () {
    CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'erreur_contenu', 'resolved' => false]);
    CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'structure', 'resolved' => false]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/flags?type=structure')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type_probleme', 'structure');
});

it('expose le document parent pour un signalement d\'article', function () {
    $article = Article::factory()->create([
        'document_id' => $this->document->id,
        'numero_article' => '12',
    ]);
    CurationFlag::create([
        'article_id' => $article->id,
        'type_probleme' => 'erreur',
        'resolved' => false,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/flags')
        ->assertOk()
        ->assertJsonPath('data.0.target.kind', 'article')
        ->assertJsonPath('data.0.target.document_id', $this->document->id)
        ->assertJsonPath('data.0.target.article_number', '12');
});

// ---------------------------------------------------------------------------
// Résolution / ré-ouverture / suppression
// ---------------------------------------------------------------------------

it('résout un signalement avec traçabilité', function () {
    $flag = CurationFlag::create([
        'document_id' => $this->document->id,
        'type_probleme' => 'erreur',
        'resolved' => false,
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/flags/{$flag->id}", ['resolved' => true])
        ->assertOk()
        ->assertJsonPath('data.resolved', true)
        ->assertJsonPath('data.resolved_by', $this->admin->name);

    $flag->refresh();
    expect($flag->resolved)->toBeTrue();
    expect($flag->resolved_at)->not->toBeNull();
    expect($flag->resolved_by)->toBe($this->admin->id);
});

it('ré-ouvre un signalement et efface la traçabilité', function () {
    $flag = CurationFlag::create([
        'document_id' => $this->document->id,
        'type_probleme' => 'erreur',
        'resolved' => true,
        'resolved_at' => now(),
        'resolved_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/flags/{$flag->id}", ['resolved' => false])
        ->assertOk()
        ->assertJsonPath('data.resolved', false);

    $flag->refresh();
    expect($flag->resolved)->toBeFalse();
    expect($flag->resolved_at)->toBeNull();
    expect($flag->resolved_by)->toBeNull();
});

it('supprime un signalement', function () {
    $flag = CurationFlag::create([
        'document_id' => $this->document->id,
        'type_probleme' => 'doublon',
        'resolved' => false,
    ]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/flags/{$flag->id}")
        ->assertOk();

    $this->assertDatabaseMissing('curation_flags', ['id' => $flag->id]);
});
