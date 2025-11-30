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
                    ->map(fn($doc) => [
                        'id' => $doc->id,
                        'titre' => $doc->titre_officiel,
                        'type' => $doc->type->nom,
                        'date' => $doc->date_publication?->format('Y-m-d'),
                    ]),
            ],
        ]);
    })->name('dashboard');

    // Legal Documents
    Route::get('documents', [App\Http\Controllers\LegalDocumentController::class, 'index'])->name('documents.index');
    Route::get('documents/{document}', [App\Http\Controllers\LegalDocumentController::class, 'show'])->name('documents.show');
});

require __DIR__.'/settings.php';
