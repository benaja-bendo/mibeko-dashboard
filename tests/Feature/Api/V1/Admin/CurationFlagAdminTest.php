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

// Note : le quota API en test est de 2 requêtes/min par utilisateur — on scinde
// pour rester sous la limite (chaque test a un admin frais via beforeEach).

it('trie les signalements bloquants en tête et filtre par source', function () {
    CurationFlag::create([
        'document_id' => $this->document->id, 'source' => 'structural',
        'type_probleme' => 'division_vide', 'severity' => 'warning', 'resolved' => false,
    ]);
    CurationFlag::create([
        'document_id' => $this->document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => false,
    ]);
    CurationFlag::create([
        'document_id' => $this->document->id, 'source' => 'llm',
        'type_probleme' => 'decoupe_fusion', 'severity' => 'warning', 'resolved' => false,
    ]);

    // Tri : la bloquante remonte en tête.
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/flags')
        ->assertStatus(200)
        ->assertJsonPath('data.0.severity', 'blocking');

    // Filtre par source.
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/flags?source=llm')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source', 'llm');
});

it('filtre les signalements par sévérité', function () {
    CurationFlag::create([
        'document_id' => $this->document->id, 'source' => 'structural',
        'type_probleme' => 'article_vide', 'severity' => 'blocking', 'resolved' => false,
    ]);
    CurationFlag::create([
        'document_id' => $this->document->id, 'source' => 'structural',
        'type_probleme' => 'division_vide', 'severity' => 'warning', 'resolved' => false,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/flags?severity=warning')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.severity', 'warning');
});

// ---------------------------------------------------------------------------
// Actions groupées (bulk)
// ---------------------------------------------------------------------------

it('résout en masse une sélection de signalements avec traçabilité', function () {
    $a = CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'erreur', 'resolved' => false]);
    $b = CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'doublon', 'resolved' => false]);
    $untouched = CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'autre', 'resolved' => false]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/flags/bulk', ['action' => 'resolve', 'ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('data.affected', 2);

    expect($a->refresh()->resolved)->toBeTrue();
    expect($a->resolved_by)->toBe($this->admin->id);
    expect($a->resolved_at)->not->toBeNull();
    expect($b->refresh()->resolved)->toBeTrue();
    // Le signalement non sélectionné reste ouvert.
    expect($untouched->refresh()->resolved)->toBeFalse();
});

it('ré-ouvre en masse une sélection et efface la traçabilité', function () {
    $a = CurationFlag::create([
        'document_id' => $this->document->id, 'type_probleme' => 'erreur',
        'resolved' => true, 'resolved_at' => now(), 'resolved_by' => $this->admin->id,
    ]);
    $b = CurationFlag::create([
        'document_id' => $this->document->id, 'type_probleme' => 'doublon',
        'resolved' => true, 'resolved_at' => now(), 'resolved_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/flags/bulk', ['action' => 'reopen', 'ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('data.affected', 2);

    expect($a->refresh()->resolved)->toBeFalse();
    expect($a->resolved_at)->toBeNull();
    expect($a->resolved_by)->toBeNull();
    expect($b->refresh()->resolved)->toBeFalse();
});

it('supprime en masse une sélection de signalements', function () {
    $a = CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'doublon', 'resolved' => false]);
    $b = CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'hors_sujet', 'resolved' => false]);
    $keep = CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'erreur', 'resolved' => false]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/flags/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('data.affected', 2);

    $this->assertDatabaseMissing('curation_flags', ['id' => $a->id]);
    $this->assertDatabaseMissing('curation_flags', ['id' => $b->id]);
    $this->assertDatabaseHas('curation_flags', ['id' => $keep->id]);
});

it('rejette une action groupée invalide', function () {
    $flag = CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'erreur', 'resolved' => false]);

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/flags/bulk', ['action' => 'purge', 'ids' => [$flag->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('action');
});

it('refuse une action groupée à un non-admin', function () {
    $flag = CurationFlag::create(['document_id' => $this->document->id, 'type_probleme' => 'erreur', 'resolved' => false]);

    $this->actingAs($this->proUser)
        ->postJson('/api/v1/admin/flags/bulk', ['action' => 'delete', 'ids' => [$flag->id]])
        ->assertForbidden();

    $this->assertDatabaseHas('curation_flags', ['id' => $flag->id]);
});
