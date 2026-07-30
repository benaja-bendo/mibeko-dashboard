<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LegalDocumentCatalogResource;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        $documents = LegalDocument::query()
            ->published()
            ->with(['type'])
            // Alimentent l'empreinte de version : une correction d'article doit
            // être détectable même quand la ligne du document n'a pas bougé.
            ->withCount('articles')
            ->withMax('articles', 'updated_at')
            ->get();

        $resources = LegalDocumentCatalogResource::collection($documents);

        return $this->success([
            // Conservés pour compatibilité avec les applications déjà
            // publiées ; aucune écriture ne les alimente (cf. catalog_version).
            'global_update_required' => cache()->get('global_update_required', false),
            'last_essential_sync' => cache()->get('last_essential_sync', now()->toIso8601String()),
            // Empreinte de tout le catalogue : inchangée, le client sait qu'il
            // n'a rien à faire sans comparer document par document.
            'catalog_version' => $this->catalogVersion($documents),
            // Horloge du serveur : le client horodate sa synchronisation
            // dessus plutôt que sur une horloge d'appareil parfois fausse.
            'server_time' => now()->toIso8601String(),
            'resources' => $resources,
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
        $stats = LegalDocument::query()
            ->published()
            ->join('document_types', 'legal_documents.type_code', '=', 'document_types.code')
            ->select('document_types.nom as type_name', 'document_types.code as type_code', DB::raw('count(legal_documents.id) as count'))
            ->groupBy('document_types.code', 'document_types.nom')
            ->get();

        return $this->success($stats, 'Statistiques récupérées avec succès');
    }

    /**
     * Empreinte de l'ensemble du catalogue.
     *
     * Change dès qu'un document entre, sort ou évolue — l'app mobile peut donc
     * conclure « rien à faire » sur une seule comparaison, sans parcourir les
     * centaines d'entrées.
     *
     * @param  Collection<int, LegalDocument>  $documents
     */
    private function catalogVersion($documents): string
    {
        $fingerprint = $documents
            ->map(fn ($document) => implode(':', [
                $document->id,
                $document->updated_at->getTimestamp(),
                $document->articles_max_updated_at ?? '',
                $document->articles_count ?? 0,
            ]))
            ->sort()
            ->implode('|');

        return md5($fingerprint);
    }
}
