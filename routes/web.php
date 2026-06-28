<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\PdfProxyController;
use App\Models\Article;
use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Racine de l'hôte API : page de statut « machine » (pas de vitrine, pas
// d'authentification ici). Les humains vont sur mibeko.fr / app.mibeko.fr.
Route::get('/', function (Request $request) {
    $payload = [
        'service' => config('app.name', 'Mibeko').' API',
        'status' => 'ok',
        'version' => 'v1',
        'documentation' => url('/docs/api'),
        'application' => 'https://app.mibeko.fr',
        'website' => 'https://mibeko.fr',
        'health' => url('/up'),
    ];

    if ($request->expectsJson()) {
        return response()->json($payload);
    }

    return response()->view('status', $payload);
})->name('home');

// Public routes for Deep Linking / Web Share. Ces pages portent les balises
// Open Graph + App Links (aperçu social, ouverture in-app) : on NE redirige PAS
// (cela casserait l'unfurl social et le deep-link mobile). À la place, quand le
// contenu est publié sur le site, on émet un `rel=canonical` vers le lecteur
// public mibeko.fr et on autorise l'indexation (consolidation SEO vers mibeko.fr).
Route::get('/article/{articleId}', function (string $articleId) {
    $article = Article::with(['document', 'activeVersion'])->findOrFail($articleId);

    $document = $article->document;
    $canonical = null;
    if ($document && $document->curation_status === LegalDocument::STATUS_PUBLISHED && $document->slug) {
        $canonical = rtrim((string) config('app.site_url'), '/')
            .'/textes/'.$document->slug.'/article-'.rawurlencode((string) $article->numero_article);
    }

    $response = response()->view('share.article', [
        'article' => $article,
        'canonical' => $canonical,
    ]);

    return $canonical ? $response->header('X-Robots-Tag', 'all') : $response;
})->name('share.article');

Route::get('/document/{documentId}', function (string $documentId) {
    $document = LegalDocument::with(['type', 'institution'])->findOrFail($documentId);

    $canonical = null;
    if ($document->curation_status === LegalDocument::STATUS_PUBLISHED && $document->slug) {
        $canonical = rtrim((string) config('app.site_url'), '/').'/textes/'.$document->slug;
    }

    $response = response()->view('share.document', [
        'document' => $document,
        'canonical' => $canonical,
    ]);

    return $canonical ? $response->header('X-Robots-Tag', 'all') : $response;
})->name('share.document');


Route::middleware(['auth', 'verified'])->group(function () {

    // PDF Proxy for inline display (fixes Minio/S3 download issue)
    Route::get('/pdf-proxy/{id}', [PdfProxyController::class, 'show'])->name('pdf.proxy');

    // Media Management
    Route::get('/api/media/files', [MediaController::class, 'listAvailableFiles'])->name('api.media.files');
    Route::post('/curation/{document}/attach-media', [MediaController::class, 'attachFile'])->name('curation.attach-media');
});
