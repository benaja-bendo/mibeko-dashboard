<?php

use App\Http\Controllers\Api\V1\ArticleSearchController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\LegalDocumentController;
use App\Http\Controllers\Api\V1\LegalDocumentExportController;
use App\Http\Controllers\Api\V1\StructureNodeController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    // Resources
    Route::apiResource('institutions', InstitutionController::class)->only(['index']);
    Route::apiResource('document-types', DocumentTypeController::class)->only(['index']);

    Route::apiResource('legal-documents', LegalDocumentController::class)->only(['index', 'show']);
    Route::get('legal-documents/{document}/tree', [StructureNodeController::class, 'tree']);
    Route::get('legal-documents/{id}/full-export', [LegalDocumentExportController::class, 'export']);

    // Article Search (for mobile app)
    Route::get('articles/search', [ArticleSearchController::class, 'search']);

    // Sync
    Route::get('sync/updates', [SyncController::class, 'updates']);
});
