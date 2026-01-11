<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard', [
            'stats' => [
                'total_documents' => \App\Models\LegalDocument::count(),
                'total_articles' => \App\Models\Article::count(),
                'recent_documents' => \App\Models\LegalDocument::with(['type', 'institution'])
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
        Route::get('/', [App\Http\Controllers\CurationController::class, 'index'])->name('index');
        Route::post('/', [App\Http\Controllers\CurationController::class, 'store'])->name('store');
        Route::get('/{document}', [App\Http\Controllers\CurationController::class, 'show'])->name('show');
        Route::patch('/{document}', [App\Http\Controllers\CurationController::class, 'update'])->name('update');
        Route::delete('/{document}', [App\Http\Controllers\CurationController::class, 'destroy'])->name('destroy');


        // Nodes
        Route::post('/{document}/nodes', [App\Http\Controllers\CurationController::class, 'storeNode'])->name('nodes.store');
        Route::put('/{document}/nodes/{node}', [App\Http\Controllers\CurationController::class, 'updateNode'])->name('nodes.update');
        Route::delete('/{document}/nodes/{node}', [App\Http\Controllers\CurationController::class, 'destroyNode'])->name('nodes.destroy');

        // Articles
        Route::post('/{document}/articles', [App\Http\Controllers\CurationController::class, 'storeArticle'])->name('articles.store');
        Route::put('/{document}/articles/{article}', [App\Http\Controllers\CurationController::class, 'updateArticle'])->name('articles.update');
        Route::delete('/{document}/articles/{article}', [App\Http\Controllers\CurationController::class, 'destroyArticle'])->name('articles.destroy');

        // Actions
        Route::post('/{document}/reorder', [App\Http\Controllers\CurationController::class, 'reorder'])->name('reorder');
        Route::patch('/{document}/source-url', [App\Http\Controllers\CurationController::class, 'updateSourceUrl'])->name('source-url.update');

        // Backward compatibility
        Route::post('/{document}/content', [App\Http\Controllers\CurationController::class, 'updateContent'])->name('content.update');
        Route::post('/{document}/structure', [App\Http\Controllers\CurationController::class, 'updateNode'])->name('structure.update'); // mapped to updateNode roughly
    });

    // PDF Proxy for inline display (fixes Minio/S3 download issue)
    Route::get('/pdf-proxy/{id}', [App\Http\Controllers\PdfProxyController::class, 'show'])->name('pdf.proxy');

    // Auditing
    Route::get('/auditing', [App\Http\Controllers\AuditController::class, 'index'])->name('auditing.index');

    // Media Management
    Route::get('/api/media/files', [App\Http\Controllers\MediaController::class, 'listAvailableFiles'])->name('api.media.files');
    Route::post('/curation/{document}/attach-media', [App\Http\Controllers\MediaController::class, 'attachFile'])->name('curation.attach-media');
});


require __DIR__.'/settings.php';
