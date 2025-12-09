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
        return Inertia::render('Dashboard', [
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
        Route::get('/{document}', [App\Http\Controllers\CurationController::class, 'show'])->name('show');
        Route::post('/{document}/structure', [App\Http\Controllers\CurationController::class, 'updateStructure'])->name('structure.update');
        Route::post('/{document}/content', [App\Http\Controllers\CurationController::class, 'updateContent'])->name('content.update');
        Route::patch('/{document}/source-url', [App\Http\Controllers\CurationController::class, 'updateSourceUrl'])->name('source-url.update');
    });

    // PDF Proxy for inline display (fixes Minio/S3 download issue)
    Route::get('/pdf-proxy', [App\Http\Controllers\PdfProxyController::class, 'show'])->name('pdf.proxy');
});

require __DIR__.'/settings.php';
