<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/**
 * Garde d'accès au corpus non publié (audit P0.1).
 *
 * Les endpoints REST publics `legal-documents` (index, search, show, tree,
 * download, pdf, export) ne doivent exposer que les documents publiés aux
 * appels non privilégiés — anonymes ou comptes sans rôle éditorial. Les
 * éditeurs/admins gardent l'accès complet : le dashboard React consomme ces
 * mêmes endpoints pour les brouillons. Un document non publié répond 404
 * (jamais 403) pour ne pas révéler son existence.
 */
beforeEach(function () {
    // Document publié avec article (visible de tous).
    $this->published = LegalDocument::factory()->create([
        'titre_officiel' => 'Loi publiée de test',
        'curation_status' => LegalDocument::STATUS_PUBLISHED,
    ]);
    $publishedArticle = Article::factory()->create(['document_id' => $this->published->id]);
    ArticleVersion::factory()->create(['article_id' => $publishedArticle->id]);

    // Brouillon avec article (réservé aux éditeurs/admins).
    $this->draft = LegalDocument::factory()->create([
        'titre_officiel' => 'Brouillon confidentiel de test',
        'curation_status' => LegalDocument::STATUS_DRAFT,
    ]);
    $this->draftArticle = Article::factory()->create(['document_id' => $this->draft->id]);
    ArticleVersion::factory()->create(['article_id' => $this->draftArticle->id]);

    $this->editor = User::factory()->create();
    $this->editor->assignRole(Role::findOrCreate('editor'));
});

it('ne liste que les documents publiés sur l\'index public', function () {
    $this->getJson('/api/v1/legal-documents')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->published->id);
});

it('clampe le filtre curation_status à « published » pour les appels non privilégiés', function () {
    // Même en demandant explicitement les brouillons, un anonyme ne reçoit que
    // le corpus publié — et surtout jamais une 400 (le site vitrine dépend de
    // ce filtre en anonyme).
    $this->getJson('/api/v1/legal-documents?filter[curation_status]=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->published->id);
});

it('accepte l\'appel exact du site vitrine (filter published + tri + pagination) en anonyme', function () {
    // Réplique fidèle de mibeko-site::fetchPublishedDocuments — ne doit jamais
    // rendre 400 sous peine de casser la page /textes au déploiement.
    $this->getJson('/api/v1/legal-documents?filter[curation_status]=published&sort=titre_officiel&per_page=24&page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->published->id);
});

it('laisse l\'éditeur lister et filtrer les brouillons', function () {
    $this->actingAs($this->editor)
        ->getJson('/api/v1/legal-documents?filter[curation_status]=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->draft->id);
});

it('masque les brouillons de la recherche de documents aux anonymes', function () {
    $this->getJson('/api/v1/legal-documents/search?q=test')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->published->id);

    // Le filtre curation_status est aussi clampé côté recherche : jamais de 400,
    // jamais de brouillon exposé, même en le demandant explicitement.
    $this->getJson('/api/v1/legal-documents/search?q=test&filter[curation_status]=draft')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->published->id);

    $this->actingAs($this->editor)
        ->getJson('/api/v1/legal-documents/search?q=test')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('répond 404 sur le show d\'un brouillon pour un anonyme, 200 pour un éditeur', function () {
    $this->getJson("/api/v1/legal-documents/{$this->draft->id}")
        ->assertNotFound();

    $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$this->draft->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->draft->id);
});

it('répond 404 sur le show d\'un brouillon pour un compte sans rôle éditorial', function () {
    $reader = User::factory()->create();

    $this->actingAs($reader)
        ->getJson("/api/v1/legal-documents/{$this->draft->id}")
        ->assertNotFound();
});

it('répond 404 sur l\'arbre d\'un brouillon pour un anonyme, 200 pour un éditeur', function () {
    $this->getJson("/api/v1/legal-documents/{$this->draft->id}/tree")
        ->assertNotFound();

    $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$this->draft->id}/tree")
        ->assertOk();
});

it('répond 404 sur le download d\'un brouillon pour un anonyme, 200 pour un éditeur', function () {
    $this->getJson("/api/v1/legal-documents/{$this->draft->id}/download")
        ->assertNotFound();

    $this->actingAs($this->editor)
        ->getJson("/api/v1/legal-documents/{$this->draft->id}/download")
        ->assertOk()
        ->assertJsonPath('data.resource_id', $this->draft->id);
});

it('répond 404 sur le pdf d\'un brouillon pour un anonyme, 200 pour un éditeur', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('draft.pdf', 'dummy content');

    $this->draft->mediaFiles()->create([
        'file_path' => 'draft.pdf',
        'object_key' => 'draft.pdf',
        'file_category' => 'SOURCE_PDF',
        'original_filename' => 'draft.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->get("/api/v1/legal-documents/{$this->draft->id}/pdf")
        ->assertNotFound();

    $this->actingAs($this->editor)
        ->get("/api/v1/legal-documents/{$this->draft->id}/pdf")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('répond 404 sur l\'export PDF d\'un brouillon pour un anonyme, 200 pour un éditeur', function () {
    $this->get("/api/v1/legal-documents/{$this->draft->id}/export")
        ->assertNotFound();

    $response = $this->actingAs($this->editor)
        ->get("/api/v1/legal-documents/{$this->draft->id}/export");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('répond 404 sur l\'export d\'un article de brouillon pour un anonyme, 200 pour un éditeur', function () {
    $this->get("/api/v1/articles/{$this->draftArticle->id}/export")
        ->assertNotFound();

    $response = $this->actingAs($this->editor)
        ->get("/api/v1/articles/{$this->draftArticle->id}/export");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('garde le corpus publié accessible aux anonymes (show + arbre)', function () {
    $this->getJson("/api/v1/legal-documents/{$this->published->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->published->id);

    $this->getJson("/api/v1/legal-documents/{$this->published->id}/tree")
        ->assertOk();
});
