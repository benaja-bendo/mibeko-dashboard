<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\Article;
use App\Http\Resources\V1\ArticleSyncResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Traits\HttpResponses;

class SyncController extends Controller
{
    use HttpResponses;

    /**
     * Get incremental updates since a specific date.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function sync(Request $request): JsonResponse
    {
        $lastSync = $request->query('last_sync_at');
        
        if (!$lastSync) {
            return $this->error(null, 'Le paramètre last_sync_at est requis', 400);
        }

        try {
            $lastSyncDate = Carbon::parse($lastSync);
        } catch (\Exception $e) {
            return $this->error(null, 'Format de date invalide pour last_sync_at', 400);
        }

        // Documents updated since last sync
        $updatedDocuments = LegalDocument::query()
            ->published()
            ->where('updated_at', '>=', $lastSyncDate)
            ->get();

        // Articles updated since last sync (belonging to published documents)
        $updatedArticles = Article::query()
            ->whereHas('document', function ($q) {
                $q->published();
            })
            ->where('updated_at', '>=', $lastSyncDate)
            ->with(['activeVersion', 'tags'])
            ->get();

        // Trashed documents (soft deletes)
        $deletedDocuments = LegalDocument::onlyTrashed()
            ->where('deleted_at', '>=', $lastSyncDate)
            ->pluck('id');

        // Trashed articles
        $deletedArticles = Article::onlyTrashed()
            ->where('deleted_at', '>=', $lastSyncDate)
            ->pluck('id');

        return $this->success([
            'timestamp' => now()->toIso8601String(),
            'updated_documents' => $updatedDocuments->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'title' => $doc->titre_officiel,
                    'type' => $doc->type_code,
                    'updated_at' => $doc->updated_at->toIso8601String(),
                ];
            }),
            'updated_articles' => ArticleSyncResource::collection($updatedArticles),
            'deleted_documents' => $deletedDocuments,
            'deleted_articles' => $deletedArticles,
        ], 'Synchronisation effectuée avec succès');
    }
}
