<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ArticleSyncResource;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SyncController extends Controller
{
    /**
     * Get articles updated since a given timestamp.
     */
    public function updates(Request $request)
    {
        $request->validate([
            'since' => ['required', 'date'],
        ]);

        $since = $request->input('since');

        // Fetch articles updated or deleted after $since
        $query = Article::query()
            ->withTrashed()
            ->with(['activeVersion', 'tags']) // Eager load tags
            ->where('updated_at', '>', $since)
            // If the item is deleted, updated_at is updated to deleted_at time usually,
            // but we can also check deleted_at > since to be sure.
            // For simplicity in Laravel SoftDeletes, updated_at IS touched on delete.
            ->orderBy('updated_at');

        // Pagination manually or just limit?
        // To keep it simple and consistent with standard sync, we might paginate.
        // But for the split return, pagination is trickier.
        // Let's grab a chunk or paginate and split the collection.
        
        $paginator = $query->paginate(500); // Larger pages for sync
        $items = $paginator->getCollection();

        $updated = $items->filter(fn ($article) => !$article->trashed());
        $deleted = $items->filter(fn ($article) => $article->trashed());

        return response()->json([
            'data' => [
                'updated' => ArticleSyncResource::collection($updated),
                'deleted_ids' => $deleted->pluck('id')->values(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
