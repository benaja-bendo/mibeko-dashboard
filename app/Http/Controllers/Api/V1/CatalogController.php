<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LegalDocumentCatalogResource;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Catalog & Sync
 */
class CatalogController extends Controller
{
    /**
     * Get the global catalog status.
     *
     * Returns the global sync state and the list of available resources.
     * Mobile app uses this to detect if it needs to update its local database.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Catalogue récupéré avec succès",
     *   "data": {
     *     "global_update_required": true,
     *     "last_essential_sync": "2026-01-05T08:00:00Z",
     *     "resources": [
     *       {
     *         "id": "uuid",
     *         "title": "Constitution de la République du Congo",
     *         "type": "CONST",
     *         "version_hash": "a1b2c3d4",
     *         "last_updated": "2026-01-04T10:00:00Z",
     *         "download_size_kb": 450
     *       }
     *     ]
     *   }
     * }
     */
    public function index(Request $request): JsonResponse
    {
        // For MVP, we consider everything essential needs update if it changed recently
        // In real impl, we would check $request->input('last_sync_date')

        $documents = LegalDocument::query()
            ->with(['type'])
            ->get();

        return $this->success([
            'global_update_required' => false, // Dynamic logic to be added
            'last_essential_sync' => now()->toIso8601String(),
            'resources' => LegalDocumentCatalogResource::collection($documents),
        ], 'Catalogue récupéré avec succès');
    }

    /**
     * Get statistics about available documents.
     *
     * Returns counts of documents grouped by type (Codes, Laws, etc.).
     *
     * @response 200 {
     *  "success": true,
     *  "message": "Statistiques récupérées avec succès",
     *  "data": [
     *    { "type_name": "Code", "type_code": "CODE", "count": 5 }
     *  ]
     * }
     */
    public function stats(): JsonResponse
    {
        $stats = \Illuminate\Support\Facades\DB::table('legal_documents')
            ->join('document_types', 'legal_documents.type_code', '=', 'document_types.code')
            ->select('document_types.nom as type_name', 'document_types.code as type_code', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
            ->groupBy('document_types.code', 'document_types.nom')
            ->get();

        return $this->success($stats, 'Statistiques récupérées avec succès');
    }
}
