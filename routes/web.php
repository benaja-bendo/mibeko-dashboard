<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\CurationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PdfProxyController;
use App\Http\Controllers\UserController;
use App\Models\Article;
use App\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

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

// Legal & Contact public pages
Route::get('/mentions-legales', fn () => Inertia::render('legal/mentions-legales'))->name('legal.mentions');
Route::get('/confidentialite', fn () => Inertia::render('legal/confidentialite'))->name('legal.privacy');
Route::get('/cgu-cgv', fn () => Inertia::render('legal/cgu-cgv'))->name('legal.terms');
Route::get('/contact', fn () => Inertia::render('contact'))->name('contact');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard', [
            'stats' => [
                'total_documents' => LegalDocument::count(),
                'total_articles' => Article::count(),
                'recent_documents' => LegalDocument::with(['type', 'institution'])
                    ->latest('date_publication')
                    ->take(5)
                    ->get()
                    ->map(fn ($doc) => [
                        'id' => $doc->id,
                        'titre' => $doc->titre_officiel,
                        'type' => $doc->type->nom,
                        'date' => $doc->date_publication?->format('Y-m-d'),
                    ]),
            ],
        ]);
    })->name('dashboard');

    // Curation Dashboard
    Route::prefix('curation')->name('curation.')->group(function () {
        Route::get('/', [CurationController::class, 'index'])->name('index');
        Route::post('/', [CurationController::class, 'store'])->name('store');
        Route::get('/{document}', [CurationController::class, 'show'])->name('show');
        Route::patch('/{document}', [CurationController::class, 'update'])->name('update');
        Route::delete('/{document}', [CurationController::class, 'destroy'])->name('destroy');

        // Nodes
        Route::post('/{document}/nodes', [CurationController::class, 'storeNode'])->name('nodes.store');
        Route::put('/{document}/nodes/{node}', [CurationController::class, 'updateNode'])->name('nodes.update');
        Route::delete('/{document}/nodes/{node}', [CurationController::class, 'destroyNode'])->name('nodes.destroy');

        // Articles
        Route::post('/{document}/articles', [CurationController::class, 'storeArticle'])->name('articles.store');
        Route::put('/{document}/articles/{article}', [CurationController::class, 'updateArticle'])->name('articles.update');
        Route::delete('/{document}/articles/{article}', [CurationController::class, 'destroyArticle'])->name('articles.destroy');

        // Actions
        Route::post('/{document}/reorder', [CurationController::class, 'reorder'])->name('reorder');
        Route::patch('/{document}/source-url', [CurationController::class, 'updateSourceUrl'])->name('source-url.update');

        // Backward compatibility
        Route::post('/{document}/content', [CurationController::class, 'updateContent'])->name('content.update');
        Route::post('/{document}/structure', [CurationController::class, 'updateNode'])->name('structure.update'); // mapped to updateNode roughly
    });

    // PDF Proxy for inline display (fixes Minio/S3 download issue)
    Route::get('/pdf-proxy/{id}', [PdfProxyController::class, 'show'])->name('pdf.proxy');

    // Auditing
    Route::get('/auditing', [AuditController::class, 'index'])->name('auditing.index');

    // Users Management
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

    // NB : la gestion des journaux officiels vit désormais dans le front
    // éditeur (mibeko-front /editor/journals) via l'API V1.

    // Media Management
    Route::get('/api/media/files', [MediaController::class, 'listAvailableFiles'])->name('api.media.files');
    Route::post('/curation/{document}/attach-media', [MediaController::class, 'attachFile'])->name('curation.attach-media');
});

require __DIR__.'/settings.php';
