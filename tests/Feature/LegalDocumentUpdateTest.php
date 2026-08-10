<?php

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();

    Permission::findOrCreate('documents.update');
    $editorRole = Role::findOrCreate('editor');
    $editorRole->givePermissionTo('documents.update');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('permet à un éditeur de corriger le titre du document', function () {
    $document = LegalDocument::factory()->create(['titre_officiel' => 'code du travaille']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'titre_officiel' => 'Code du travail',
        ])
        ->assertOk()
        ->assertJsonPath('data.titre_officiel', 'Code du travail');

    expect($document->fresh()->titre_officiel)->toBe('Code du travail');
});

it('met à jour les métadonnées (NOR, dates, statut juridique)', function () {
    $document = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'reference_nor' => 'NOR-2026-042',
            'date_signature' => '1975-03-15',
            'statut' => 'abroge',
        ])
        ->assertOk();

    $fresh = $document->fresh();
    expect($fresh->reference_nor)->toBe('NOR-2026-042')
        ->and($fresh->date_signature->toDateString())->toBe('1975-03-15')
        ->and($fresh->statut)->toBe('abroge');
});

it('publie un document qui possède des articles', function () {
    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);
    Article::factory()->create(['document_id' => $document->id]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.curation_status', LegalDocument::STATUS_PUBLISHED);
});

it('refuse de publier un document sans article', function () {
    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('refuse de publier un document avec des anomalies de curation non résolues', function () {
    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);
    Article::factory()->create(['document_id' => $document->id]);
    CurationFlag::create([
        'document_id' => $document->id,
        'type_probleme' => 'article_manquant',
        'description' => 'Article(s) 2 absent(s) (saut de 1 à 3).',
        'resolved' => false,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('force la publication malgré des anomalies de curation non résolues quand force=true', function () {
    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);
    Article::factory()->create(['document_id' => $document->id]);
    CurationFlag::create([
        'document_id' => $document->id,
        'type_probleme' => 'article_manquant',
        'description' => 'Article(s) 2 absent(s) (saut de 1 à 3).',
        'resolved' => false,
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
            'force' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.curation_status', LegalDocument::STATUS_PUBLISHED);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_PUBLISHED);
});

it('refuse même avec force=true de publier un document sans article', function () {
    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
            'force' => true,
        ])
        ->assertStatus(422);

    expect($document->fresh()->curation_status)->toBe(LegalDocument::STATUS_REVIEW);
});

it('publie un document dont toutes les anomalies de curation sont résolues', function () {
    $document = LegalDocument::factory()->create(['curation_status' => LegalDocument::STATUS_REVIEW]);
    Article::factory()->create(['document_id' => $document->id]);
    CurationFlag::create([
        'document_id' => $document->id,
        'type_probleme' => 'article_manquant',
        'description' => 'Article(s) 2 absent(s) (saut de 1 à 3).',
        'resolved' => true,
        'resolved_at' => now(),
    ]);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'curation_status' => LegalDocument::STATUS_PUBLISHED,
        ])
        ->assertOk()
        ->assertJsonPath('data.curation_status', LegalDocument::STATUS_PUBLISHED);
});

it('rejette un statut juridique invalide', function () {
    $document = LegalDocument::factory()->create();

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'statut' => 'annule',
        ])
        ->assertStatus(422);
});

it('refuse la mise à jour à un utilisateur sans rôle éditeur', function () {
    $document = LegalDocument::factory()->create();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->patchJson("/api/v1/legal-documents/{$document->id}", [
            'titre_officiel' => 'Tentative',
        ])
        ->assertForbidden();
});

// --- Vérification du statut juridique (étape 0 du protocole, 10/08/2026) -----
// `statut` a « vigueur » pour valeur par défaut en base : sur un document que
// personne n'a contrôlé, il répète le défaut au lieu d'affirmer quoi que ce
// soit. C'est ainsi que les 795 documents publiés se sont retrouvés à
// présenter comme applicables, en même temps, trois Constitutions successives.

it('ne prétend pas qu\'un statut jamais posé a été vérifié', function () {
    $document = LegalDocument::factory()->create(['statut' => 'vigueur']);

    expect($document->statut_verifie_le)->toBeNull();

    $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('data.statut', 'vigueur')
        ->assertJsonPath('data.statut_verifie', false)
        ->assertJsonPath('data.statut_verifie_le', null);
});

it('reçoit « vigueur » par défaut de la base, sans que personne ne l\'ait décidé', function () {
    // La racine du défaut : un document inséré sans statut est déclaré en
    // vigueur par la valeur par défaut de la colonne. Ce test garde le fait,
    // pour que la trace de vérification garde un sens — si un jour le défaut
    // devient « non vérifié », c'est ici qu'on le verra.
    $id = (string) Str::uuid();
    DB::table('legal_documents')->insert([
        'id' => $id,
        'titre_officiel' => 'Texte inséré sans statut',
        'slug' => 'texte-insere-sans-statut',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ligne = DB::table('legal_documents')->where('id', $id)->first();
    expect($ligne->statut)->toBe('vigueur')
        ->and($ligne->statut_verifie_le)->toBeNull();
});

it('horodate et attribue la vérification quand un éditeur pose le statut', function () {
    $document = LegalDocument::factory()->create(['statut' => 'vigueur']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['statut' => 'abroge'])
        ->assertOk()
        ->assertJsonPath('data.statut_verifie', true);

    $document->refresh();
    expect($document->statut)->toBe('abroge')
        ->and($document->statut_verifie_le)->not->toBeNull()
        ->and($document->statut_verifie_par)->toBe($this->editor->id);
});

it('vaut vérification même quand l\'éditeur confirme « vigueur » sans le changer', function () {
    // Le cas qui compte : confirmer que le texte EST en vigueur est une
    // décision éditoriale, pas un non-événement. Sans cette trace, un document
    // relu resterait indiscernable d'un document jamais ouvert.
    $document = LegalDocument::factory()->create(['statut' => 'vigueur']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['statut' => 'vigueur'])
        ->assertOk();

    expect($document->fresh()->statut_verifie_le)->not->toBeNull();
});

it('ne touche pas à la vérification quand la modification ne porte pas sur le statut', function () {
    $document = LegalDocument::factory()->create(['titre_officiel' => 'Ancien titre']);

    $this->actingAs($this->editor)
        ->patchJson("/api/v1/legal-documents/{$document->id}", ['titre_officiel' => 'Nouveau titre'])
        ->assertOk();

    expect($document->fresh()->statut_verifie_le)->toBeNull();
});
