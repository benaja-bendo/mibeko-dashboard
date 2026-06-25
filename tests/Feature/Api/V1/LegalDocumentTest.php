<?php

use App\Models\ArticleVersion;
use App\Models\CurationFlag;
use App\Models\Institution;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('can filter legal documents by status', function () {
    LegalDocument::factory()->hasArticles(1)->create(['statut' => 'vigueur']);
    LegalDocument::factory()->hasArticles(1)->create(['statut' => 'projet']);

    $response = $this->getJson('/api/v1/legal-documents?filter[statut]=vigueur');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'vigueur');
});

it('can search legal documents by title', function () {
    LegalDocument::factory()->hasArticles(1)->create(['titre_officiel' => 'Unique Title']);
    LegalDocument::factory()->hasArticles(1)->create(['titre_officiel' => 'Other Title']);

    $response = $this->getJson('/api/v1/legal-documents?filter[titre_officiel]=Unique');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Unique Title');
});

it('can list institutions', function () {
    Institution::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/institutions');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('can get document tree hierarchy', function () {
    $document = LegalDocument::factory()->create();
    StructureNode::factory()->count(2)->create([
        'document_id' => $document->id,
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/tree");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('includes orphan articles at the root of the tree', function () {
    $document = LegalDocument::factory()->create();

    $node = StructureNode::factory()->create(['document_id' => $document->id]);
    $document->articles()->create([
        'numero_article' => '1',
        'ordre_affichage' => 1,
        'parent_node_id' => $node->id,
        'validation_status' => 'validated',
    ]);

    // Acte court type JO : article rattaché au document sans nœud de structure.
    $orphan = $document->articles()->create([
        'numero_article' => '2',
        'ordre_affichage' => 2,
        'validation_status' => 'validated',
    ]);
    $orphan->versions()->create([
        'contenu_texte' => 'Contenu article orphelin',
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/tree");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');

    $orphanEntry = collect($response->json('data'))->firstWhere('type', 'ARTICLE');
    expect($orphanEntry)->not->toBeNull()
        ->and($orphanEntry['parent_id'])->toBeNull()
        ->and($orphanEntry['number'])->toBe('2')
        ->and($orphanEntry['content'])->toBe('Contenu article orphelin');
});

it('orders the orphan preamble before structure nodes by shared root order', function () {
    $document = LegalDocument::factory()->create();

    // Préambule : article orphelin en tête (clé d'ordre racine = 0).
    $preamble = $document->articles()->create([
        'numero_article' => 'PREAMBULE',
        'ordre_affichage' => 0,
        'validation_status' => 'validated',
    ]);
    $preamble->versions()->create([
        'contenu_texte' => 'La ministre... Vu la Constitution',
        'validity_period' => ArticleVersion::makeValidityPeriod('2020-01-01'),
        'validation_status' => 'validated',
    ]);

    // Chapitres structurés APRÈS le préambule (clés d'ordre racine 1 et 2).
    StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'CHAPITRE',
        'sort_order' => 1,
        'tree_path' => 'c1',
    ]);
    StructureNode::factory()->create([
        'document_id' => $document->id,
        'type_unite' => 'CHAPITRE',
        'sort_order' => 2,
        'tree_path' => 'c2',
    ]);

    $response = $this->getJson("/api/v1/legal-documents/{$document->id}/tree");

    $response->assertStatus(200)->assertJsonCount(3, 'data');

    // Le préambule (orphelin, ordre 0) doit être EN TÊTE, avant les chapitres,
    // pas annexé à la fin.
    expect($response->json('data.0.type'))->toBe('ARTICLE')
        ->and($response->json('data.0.number'))->toBe('PREAMBULE')
        ->and($response->json('data.1.type'))->toBe('CHAPITRE')
        ->and($response->json('data.2.type'))->toBe('CHAPITRE');
});

it('does not bulk publish documents without articles', function () {
    Role::findOrCreate('editor');
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $withArticles = LegalDocument::factory()->hasArticles(1)->create(['curation_status' => 'review']);
    $empty = LegalDocument::factory()->create(['curation_status' => 'review']);

    $response = $this->actingAs($editor)->patchJson('/api/v1/legal-documents/bulk', [
        'ids' => [$withArticles->id, $empty->id],
        'action' => 'set_curation_status',
        'value' => 'published',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.updated_count', 1)
        ->assertJsonPath('data.skipped_count', 1);

    expect($withArticles->fresh()->curation_status)->toBe('published')
        ->and($empty->fresh()->curation_status)->toBe('review');
});

it('does not bulk publish documents with unresolved curation flags', function () {
    Role::findOrCreate('editor');
    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $clean = LegalDocument::factory()->hasArticles(1)->create(['curation_status' => 'review']);
    $flagged = LegalDocument::factory()->hasArticles(1)->create(['curation_status' => 'review']);
    CurationFlag::create([
        'document_id' => $flagged->id,
        'type_probleme' => 'article_doublon',
        'description' => "Numéro d'article 1 répété consécutivement.",
        'resolved' => false,
    ]);

    $response = $this->actingAs($editor)->patchJson('/api/v1/legal-documents/bulk', [
        'ids' => [$clean->id, $flagged->id],
        'action' => 'set_curation_status',
        'value' => 'published',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.updated_count', 1)
        ->assertJsonPath('data.skipped_count', 1);

    expect($clean->fresh()->curation_status)->toBe('published')
        ->and($flagged->fresh()->curation_status)->toBe('review');
});

it('can login and get me', function () {
    $user = User::factory()->withoutTwoFactor()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    $response = $this->postJson('/api/v1/login', [
        'email' => 'test@example.com',
        'password' => 'password',
        'device_name' => 'test-device',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => ['token']]);

    $token = $response->json('data.token');

    $meResponse = $this->withHeaders(['Authorization' => "Bearer $token"])
        ->getJson('/api/v1/me');

    $meResponse->assertStatus(200)
        ->assertJsonPath('data.user.email', 'test@example.com');
});
