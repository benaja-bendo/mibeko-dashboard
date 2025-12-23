<?php

use App\Http\Controllers\Api\V1\LegalDocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::apiResource('legal-documents', LegalDocumentController::class)->only(['index', 'show']);
});
