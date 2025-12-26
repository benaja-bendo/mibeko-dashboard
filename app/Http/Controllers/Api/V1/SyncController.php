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
    public function updates(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'since' => ['required', 'date'],
        ]);

        $since = $request->input('since');

        $articles = Article::query()
            ->with(['activeVersion'])
            ->where('updated_at', '>', $since)
            ->paginate(100);

        return ArticleSyncResource::collection($articles);
    }
}
