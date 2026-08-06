<?php

use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('can proxy a journal pdf (type=journal)', function () {
    // Un journal n'a pas de MediaFile : le proxy résout son disque via le
    // disque de stockage par défaut (s3/MinIO en prod, local en CI). On fake
    // donc ce même disque pour que le test soit indépendant de FILESYSTEM_DISK.
    $disk = config('filesystems.default');
    Storage::fake($disk);
    Storage::disk($disk)->put('official_journals/jo.pdf', 'dummy journal');

    $journal = OfficialJournal::factory()->create([
        'file_path' => 'official_journals/jo.pdf',
    ]);

    $response = $this->get("/api/v1/legal-documents/{$journal->id}/pdf?type=journal");

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

it('hides the pdf of an unpublished journal', function () {
    // Le PDF d'un JO non publié porte le texte source d'actes encore en
    // brouillon : la route étant publique, il doit être introuvable.
    $disk = config('filesystems.default');
    Storage::fake($disk);
    Storage::disk($disk)->put('official_journals/jo-brouillon.pdf', 'dummy journal');

    $journal = OfficialJournal::factory()->create([
        'file_path' => 'official_journals/jo-brouillon.pdf',
        'is_published' => false,
    ]);

    $this->get("/api/v1/legal-documents/{$journal->id}/pdf?type=journal")
        ->assertNotFound();
});

it('can proxy a pdf file', function () {
    Storage::fake('s3');
    Storage::disk('s3')->put('test.pdf', 'dummy content');

    // Avec article : le garde public n'expose que le publié AVEC articles.
    $document = LegalDocument::factory()->hasArticles(1)->create();

    $document->mediaFiles()->create([
        'file_path' => 'test.pdf',
        'object_key' => 'test.pdf',
        'file_category' => 'SOURCE_PDF',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $response = $this->get("/api/v1/legal-documents/{$document->id}/pdf");

    $response->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

it('returns 404 if pdf does not exist in storage', function () {
    Storage::fake('s3');

    $document = LegalDocument::factory()->hasArticles(1)->create();

    $document->mediaFiles()->create([
        'file_path' => 'missing.pdf',
        'object_key' => 'missing.pdf',
        'file_category' => 'SOURCE_PDF',
        'original_filename' => 'missing.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $response = $this->get("/api/v1/legal-documents/{$document->id}/pdf");

    $response->assertStatus(404);
});

it('returns 404 if document has no pdf', function () {
    $document = LegalDocument::factory()->hasArticles(1)->create();

    $response = $this->get("/api/v1/legal-documents/{$document->id}/pdf");

    $response->assertStatus(404);
});

it('sert le proxy PDF sous le quota corpus_read, jamais sous throttle:api', function () {
    // Le lecteur PDF mobile charge cette URL dans une WKWebView/WebView native
    // (PdfViewer.ios.kt : NSURLRequest nu), qui n'envoie ni `Authorization` ni
    // `X-Mibeko-Device` : la requête est anonyme et se retrouve limitée PAR IP.
    // Sous `throttle:api` (60/min) elle rendait un 429 dans la visionneuse —
    // constaté en production le 06/08/2026. `corpus_read` (300/min par IP)
    // absorbe le CGNAT des opérateurs congolais.
    //
    // La régression a déjà eu lieu une fois : `download` avait été déplacé sous
    // `corpus_read` et `/pdf`, juste en dessous, avait été oublié alors qu'il
    // fait la même chose. D'où ce garde-fou sur la route elle-même.
    $route = collect(Route::getRoutes())
        ->first(fn ($route) => $route->uri() === 'api/v1/legal-documents/{id}/pdf');

    // `gatherMiddleware()` liste ENCORE `throttle:api` (hérité du préfixe v1) :
    // `withoutMiddleware` enregistre l'exclusion à part, il ne retire rien de la
    // liste. C'est le middleware effectif — gathered MOINS excluded — qui décide
    // du quota réellement appliqué, donc c'est lui qu'on teste.
    $effectif = array_values(array_diff($route->gatherMiddleware(), $route->excludedMiddleware()));

    expect($effectif)->toContain('throttle:corpus_read')
        ->and($effectif)->not->toContain('throttle:api');
});
