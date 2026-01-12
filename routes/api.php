<?php

use App\Http\Controllers\Api\V1\ArticleSearchController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\LegalDocumentController;
use App\Http\Controllers\Api\V1\LegalDocumentDownloadController;
use App\Http\Controllers\Api\V1\LegalDocumentExportController;
use App\Http\Controllers\Api\V1\StructureNodeController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\PdfProxyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    // Resources
    Route::get('home', [HomeController::class, 'index']);
    Route::get('catalog', [CatalogController::class, 'index']); // BE1
    Route::get('catalog/stats', [CatalogController::class, 'stats']);

    Route::apiResource('institutions', InstitutionController::class)->only(['index']);
    Route::apiResource('document-types', DocumentTypeController::class)->only(['index']);

    Route::apiResource('legal-documents', LegalDocumentController::class)->only(['index', 'show']);
    Route::get('legal-documents/{document}/tree', [StructureNodeController::class, 'tree']);

    // BE2 - Flat List Download
    Route::get('legal-documents/{id}/download', [LegalDocumentDownloadController::class, 'download']);

    // BE4 - PDF Proxy
    Route::get('legal-documents/{id}/pdf', [PdfProxyController::class, 'show']);

    // BE5 - PDF Export
    Route::get('legal-documents/{id}/export', [LegalDocumentExportController::class, 'export']);
    Route::get('articles/{id}/export', [LegalDocumentExportController::class, 'exportArticle']);

    // Article Search (for mobile app) - BE3 Hybrid
    Route::get('search', [ArticleSearchController::class, 'search']);
    Route::get('articles/search', [ArticleSearchController::class, 'search']);

    // Sync - @deprecated by CatalogController but kept for backward compatibility if any
    Route::get('sync/updates', [SyncController::class, 'updates']);
});
