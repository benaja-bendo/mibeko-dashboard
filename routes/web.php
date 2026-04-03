<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\CurationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PdfProxyController;
use App\Models\Article;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

// Public routes for Deep Linking / Web Share
Route::get('/article/{articleId}', function (string $articleId) {
    $article = Article::with(['document', 'activeVersion'])->findOrFail($articleId);

    return view('share.article', compact('article'));
})->name('share.article');

Route::get('/document/{documentId}', function (string $documentId) {
    $document = LegalDocument::with(['type', 'institution'])->findOrFail($documentId);

    return view('share.document', compact('document'));
})->name('share.document');

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

    // Media Management
    Route::get('/api/media/files', [MediaController::class, 'listAvailableFiles'])->name('api.media.files');
    Route::post('/curation/{document}/attach-media', [MediaController::class, 'attachFile'])->name('curation.attach-media');
});

require __DIR__.'/settings.php';
