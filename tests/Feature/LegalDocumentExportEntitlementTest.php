<?php

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\User;
use App\Observers\ArticleVersionObserver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Ai\Embeddings;
use Spatie\Permission\Models\Role;

/**
 * Verrouille l'export PDF derrière l'entitlement `export` — mibeko-dashboard#86.
 * La génération PDF elle-même (rendu, cache) est couverte par
 * LegalDocumentExportTest ; ce fichier ne teste que la garde d'accès :
 *  - un Bearer Sanctum pro passe (dashboard éditeur, client authentifié) ;
 *  - un Bearer non-pro, ou aucun Bearer sans signature valide, est refusé ;
 *  - une URL mintée par les endpoints `export-token` fonctionne SANS Bearer
 *    (clic direct <a href> du lecteur Bibliothèque, URL brute mobile).
 */
beforeEach(function () {
    ArticleVersionObserver::$shouldSkipEmbeddings = true;
    Embeddings::fake();
    Storage::fake('s3');

    DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);

    $this->document = LegalDocument::factory()->create([
        'type_code' => 'LOI',
        'titre_officiel' => 'Loi de test sur l\'entitlement export',
        'curation_status' => 'published',
        'legal_scope' => 'national',
    ]);

    $this->article = Article::factory()->create(['document_id' => $this->document->id]);
    ArticleVersion::factory()->create([
        'article_id' => $this->article->id,
        'contenu_texte' => 'Contenu de test.',
        'validity_period' => '[2024-05-01,)',
    ]);

    Role::findOrCreate('user_pro');
    $this->pro = User::factory()->create();
    $this->pro->assignRole('user_pro');

    $this->standard = User::factory()->create();
});

// --- Accès direct (Bearer Sanctum) ---

it('refuse l\'export à un anonyme sans signature', function () {
    $this->get("/api/v1/legal-documents/{$this->document->id}/export")->assertForbidden();
    $this->get("/api/v1/articles/{$this->article->id}/export")->assertForbidden();
});

it('refuse l\'export à un compte authentifié non-pro', function () {
    $this->actingAs($this->standard)
        ->get("/api/v1/legal-documents/{$this->document->id}/export")
        ->assertForbidden();
});

it('autorise l\'export à un compte pro authentifié, sans signature', function () {
    $this->actingAs($this->pro)
        ->get("/api/v1/legal-documents/{$this->document->id}/export")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

// --- Mint du jeton signé (export-token) ---

it('refuse de minter un jeton à un compte non-pro', function () {
    $this->actingAs($this->standard)
        ->getJson("/api/v1/legal-documents/{$this->document->id}/export-token")
        ->assertForbidden();
});

it('refuse de minter un jeton à un anonyme', function () {
    $this->getJson("/api/v1/legal-documents/{$this->document->id}/export-token")
        ->assertUnauthorized();
});

it('mint un jeton signé pour un compte pro, utilisable sans Bearer', function () {
    $response = $this->actingAs($this->pro)
        ->getJson("/api/v1/legal-documents/{$this->document->id}/export-token")
        ->assertOk();

    $url = $response->json('data.url');
    expect($url)->toContain('/legal-documents/'.$this->document->id.'/export')
        ->and($url)->toContain('signature=');

    // L'URL est absolue (APP_URL) : ne garder que le chemin + la requête
    // pour la rejouer sur le client de test, sans jamais porter de Bearer.
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->get($path)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('mint un jeton signé pour l\'export d\'un article, utilisable sans Bearer', function () {
    $response = $this->actingAs($this->pro)
        ->getJson("/api/v1/articles/{$this->article->id}/export-token")
        ->assertOk();

    $url = $response->json('data.url');
    $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

    $this->get($path)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('refuse une signature invalide', function () {
    $valid = URL::temporarySignedRoute('legal-documents.export.signed', now()->addMinutes(1), ['id' => $this->document->id]);
    $tampered = str_replace('signature=', 'signature=0000', $valid);
    $path = parse_url($tampered, PHP_URL_PATH).'?'.parse_url($tampered, PHP_URL_QUERY);

    $this->get($path)->assertForbidden();
});

it('refuse une signature expirée', function () {
    $expired = URL::temporarySignedRoute('legal-documents.export.signed', now()->subMinute(), ['id' => $this->document->id]);
    $path = parse_url($expired, PHP_URL_PATH).'?'.parse_url($expired, PHP_URL_QUERY);

    $this->get($path)->assertForbidden();
});
