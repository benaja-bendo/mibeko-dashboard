<?php

use App\Ai\ThemeClassifier;
use App\Models\Article;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\Tag;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Cache::flush();

    foreach (['admin', 'user_pro'] as $role) {
        Role::findOrCreate($role);
    }

    // L'édition d'un document est gardée par la policy (permission documents.update).
    $editorRole = Role::findOrCreate('editor');
    Permission::findOrCreate('documents.update');
    $editorRole->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');

    $this->proUser = User::factory()->create();
    $this->proUser->assignRole('user_pro');

    // Taxonomie de thèmes
    $this->travail = Tag::create(['name' => 'Travail & emploi', 'slug' => 'travail', 'icon' => 'briefcase', 'display_order' => 1]);
    $this->famille = Tag::create(['name' => 'Famille & personnes', 'slug' => 'famille', 'icon' => 'users', 'display_order' => 2]);

    DocumentType::firstOrCreate(['code' => 'LOI'], ['nom' => 'Loi', 'niveau_hierarchique' => 1]);

    $this->document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Code du travail',
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
    ]);

    $this->articleA = Article::factory()->create(['document_id' => $this->document->id, 'numero_article' => '1']);
    $this->articleB = Article::factory()->create(['document_id' => $this->document->id, 'numero_article' => '2']);
});

// ---------------------------------------------------------------------------
// Assignation & propagation
// ---------------------------------------------------------------------------

it('refuse l\'assignation de thèmes à un non-éditeur', function () {
    $this->actingAs($this->proUser)
        ->patchJson("/api/v1/legal-documents/{$this->document->id}", ['themes' => [$this->travail->id]])
        ->assertForbidden();
});

it('assigne des thèmes à un document et les propage à ses articles', function () {
    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$this->document->id}", ['themes' => [$this->travail->id]])
        ->assertOk()
        ->assertJsonPath('data.themes.0.slug', 'travail');

    // Document tagué
    $this->assertDatabaseHas('taggables', [
        'tag_id' => $this->travail->id,
        'taggable_id' => $this->document->id,
        'taggable_type' => LegalDocument::class,
    ]);

    // Propagation à chaque article
    foreach ([$this->articleA, $this->articleB] as $article) {
        $this->assertDatabaseHas('taggables', [
            'tag_id' => $this->travail->id,
            'taggable_id' => $article->id,
            'taggable_type' => Article::class,
        ]);
    }
});

it('remplace les thèmes (et la propagation) lors d\'une nouvelle assignation', function () {
    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$this->document->id}", ['themes' => [$this->travail->id]])
        ->assertOk();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$this->document->id}", ['themes' => [$this->famille->id]])
        ->assertOk();

    expect($this->document->fresh()->tags()->pluck('slug')->all())->toBe(['famille']);
    $this->assertDatabaseMissing('taggables', [
        'tag_id' => $this->travail->id,
        'taggable_id' => $this->articleA->id,
        'taggable_type' => Article::class,
    ]);
    $this->assertDatabaseHas('taggables', [
        'tag_id' => $this->famille->id,
        'taggable_id' => $this->articleA->id,
        'taggable_type' => Article::class,
    ]);
});

// ---------------------------------------------------------------------------
// Parcours / listing
// ---------------------------------------------------------------------------

it('liste les thèmes avec le nombre de textes publiés', function () {
    $this->document->tags()->sync([$this->travail->id]);

    $this->actingAs($this->proUser)
        ->getJson('/api/v1/library/themes')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'travail')
        ->assertJsonPath('data.0.documents_count', 1)
        ->assertJsonPath('data.1.documents_count', 0);
});

it('liste les textes publiés d\'un thème (parcours)', function () {
    $this->document->tags()->sync([$this->travail->id]);

    $this->actingAs($this->proUser)
        ->getJson('/api/v1/library/themes/travail')
        ->assertOk()
        ->assertJsonPath('data.theme.slug', 'travail')
        ->assertJsonCount(1, 'data.documents')
        ->assertJsonPath('data.documents.0.id', $this->document->id);
});

it('renvoie proprement un thème sans texte', function () {
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/library/themes/famille')
        ->assertOk()
        ->assertJsonPath('data.theme.slug', 'famille')
        ->assertJsonCount(0, 'data.documents');
});

it('exige une requête texte pour la recherche d\'articles (pas de thème seul)', function () {
    // Le moteur full-text refuse une recherche sans `q` (le thème seul passe par
    // le parcours documentaire, pas par /library/search).
    $this->actingAs($this->proUser)
        ->getJson('/api/v1/library/search?tag=travail')
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// Suggestion IA
// ---------------------------------------------------------------------------

it('suggère des thèmes via l\'IA en filtrant à la taxonomie', function () {
    ThemeClassifier::fake(['{"slugs": ["travail", "slug-inexistant"]}']);

    $this->actingAs($this->editor)
        ->postJson("/api/v1/legal-documents/{$this->document->id}/suggest-themes")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'travail');
});

it('refuse la suggestion IA à un non-éditeur', function () {
    $this->actingAs($this->proUser)
        ->postJson("/api/v1/legal-documents/{$this->document->id}/suggest-themes")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Admin — gestion des thèmes (icône/description/ordre)
// ---------------------------------------------------------------------------

it('crée un thème avec icône et description côté admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->postJson('/api/v1/admin/tags', [
            'name' => 'Logement & foncier',
            'icon' => 'home',
            'description' => 'Bail, propriété, expropriation.',
            'display_order' => 3,
        ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'logement-foncier')
        ->assertJsonPath('data.icon', 'home')
        ->assertJsonPath('data.display_order', 3);
});
