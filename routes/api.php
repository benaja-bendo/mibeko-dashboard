<?php

use App\Http\Controllers\Api\V1\AiAssistantController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\ArticleSearchController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CurationFlagController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DocumentRelationController;
use App\Http\Controllers\Api\V1\DocumentTypeController;
use App\Http\Controllers\Api\V1\DossierExportController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\LegalDocumentController;
use App\Http\Controllers\Api\V1\LegalDocumentDownloadController;
use App\Http\Controllers\Api\V1\LegalDocumentExportController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfficialJournalController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\StructureNodeController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\PdfProxyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Auth
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('auth/firebase', [AuthController::class, 'firebaseLogin']);

    // Device Registration (No Auth required)
    Route::post('devices/register', [DeviceController::class, 'register']);
    Route::post('devices/unregister', [DeviceController::class, 'unregister']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Profile
        Route::get('profile', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('profile/password', [ProfileController::class, 'updatePassword']);

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        // Assistant IA (Mibeko IA)
        Route::get('assistant/conversations', [AiAssistantController::class, 'index']);
        Route::get('assistant/conversations/{id}', [AiAssistantController::class, 'show']);
        Route::put('assistant/conversations/{id}', [AiAssistantController::class, 'update']);
        Route::delete('assistant/conversations/{id}', [AiAssistantController::class, 'destroy']);
        Route::post('assistant/chat/{id?}', [AiAssistantController::class, 'chat'])->middleware('throttle:ai_assistant');
    });

    // Resources
    Route::get('home', [HomeController::class, 'index']);
    // Catalog & Sync
    Route::get('catalog', [CatalogController::class, 'index']); // BE1
    Route::get('catalog/stats', [CatalogController::class, 'stats']);
    Route::get('sync', [SyncController::class, 'sync']);

    Route::apiResource('institutions', InstitutionController::class)->only(['index']);
    Route::apiResource('document-types', DocumentTypeController::class)->only(['index']);
    Route::apiResource('official-journals', OfficialJournalController::class)->only(['index', 'show'])->names('api.official-journals');

    Route::get('legal-documents/search', [LegalDocumentController::class, 'search']);
    Route::apiResource('legal-documents', LegalDocumentController::class)->only(['index', 'show']);
    Route::get('legal-documents/{document}/tree', [StructureNodeController::class, 'tree']);

    // Bulk update — editor + admin only
    Route::middleware(['auth:sanctum', 'role:editor|admin'])->group(function () {
        Route::patch('legal-documents/bulk', [LegalDocumentController::class, 'bulkUpdate']);
    });

    // Structure & Articles management
    // Article Search (for mobile app) - BE3 Hybrid
    Route::get('search', [ArticleSearchController::class, 'search']);
    Route::get('articles/search', [ArticleSearchController::class, 'search']);

    // Write operations — editor + admin only
    Route::middleware(['auth:sanctum', 'role:editor|admin'])->group(function () {
        Route::post('structure-nodes/{id}/move', [StructureNodeController::class, 'move']);
        Route::apiResource('structure-nodes', StructureNodeController::class)->except(['index', 'show']);
        Route::apiResource('articles', ArticleController::class)->except(['index']);
        Route::post('articles/{article}/versions', [ArticleController::class, 'addVersion']);

        Route::post('articles/{article}/relations', [DocumentRelationController::class, 'store']);
        Route::delete('relations/{id}', [DocumentRelationController::class, 'destroy']);
    });

    // Read relations — any authenticated user
    Route::get('articles/{article}/relations', [DocumentRelationController::class, 'index']);
    Route::get('relations/search', [DocumentRelationController::class, 'searchTargets']);

    // BE2 - Flat List Download
    Route::get('legal-documents/{id}/download', [LegalDocumentDownloadController::class, 'download']);

    // BE4 - PDF Proxy
    Route::get('legal-documents/{id}/pdf', [PdfProxyController::class, 'show']);

    // BE5 - PDF Export
    Route::get('legal-documents/{id}/export', [LegalDocumentExportController::class, 'export']);
    Route::get('articles/{id}/export', [LegalDocumentExportController::class, 'exportArticle']);

    // Curation / Signalements (Mobile App)
    Route::post('reports', [CurationFlagController::class, 'store']);

    // BE6 - Dossier PDF Export
    Route::post('dossiers/export-pdf', [DossierExportController::class, 'exportPdf']);
});
