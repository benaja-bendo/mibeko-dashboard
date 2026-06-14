<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreTagRequest;
use App\Http\Requests\Api\V1\Admin\UpdateTagRequest;
use App\Http\Resources\V1\Admin\TagResource;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;

/**
 * Gestion des tags (taxonomie transverse) depuis l'espace admin.
 *
 * @group Admin / Référentiels
 */
class TagController extends Controller
{
    public function index(): JsonResponse
    {
        $tags = Tag::withCount(['legalDocuments', 'articles'])
            ->orderBy('name')
            ->get();

        return $this->success(
            TagResource::collection($tags),
            'Tags récupérés avec succès'
        );
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = Tag::create([
            'name' => $request->validated('name'),
            'slug' => $request->validated('slug'),
        ]);

        $tag->loadCount(['legalDocuments', 'articles']);

        return $this->success(
            new TagResource($tag),
            'Tag créé avec succès',
            201
        );
    }

    public function update(UpdateTagRequest $request, Tag $tag): JsonResponse
    {
        $tag->name = $request->validated('name');

        if ($request->filled('slug')) {
            $tag->slug = $request->validated('slug');
        }

        $tag->save();
        $tag->loadCount(['legalDocuments', 'articles']);

        return $this->success(
            new TagResource($tag),
            'Tag mis à jour avec succès'
        );
    }

    /**
     * Supprime un tag — refusé s'il est encore attaché à des documents ou
     * des articles.
     */
    public function destroy(Tag $tag): JsonResponse
    {
        $usage = $tag->legalDocuments()->count() + $tag->articles()->count();

        if ($usage > 0) {
            return $this->error(
                ['usage_count' => $usage],
                "Impossible de supprimer : ce tag est utilisé {$usage} fois.",
                409
            );
        }

        $tag->delete();

        return $this->success(null, 'Tag supprimé avec succès');
    }
}
