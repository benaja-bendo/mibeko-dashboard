<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ArticleBriefResource;
use App\Http\Resources\V1\StructureNodeResource;
use App\Models\Article;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @group Document Structure
 */
class StructureNodeController extends Controller
{
    /**
     * Get document hierarchy.
     *
     * Le binding implicite applique le scope SoftDeletes : un document supprimé
     * renvoie 404 au lieu d'exposer sa structure et ses articles.
     */
    public function tree(Request $request, LegalDocument $document): JsonResponse
    {
        $nodes = StructureNode::query()
            ->where('document_id', $document->id)
            ->with(['articles.activeVersion', 'articles.versions' => function ($q) {
                $q->orderByDesc('created_at');
            }])
            ->orderBy('sort_order')
            ->get();

        /**
         * Les actes courts (arrêtés, décrets issus d'un JO) n'ont pas de structure :
         * leurs articles sont rattachés directement au document (parent_node_id NULL).
         * On les annexe à la réponse plate pour que le viewer les affiche à la racine.
         */
        $orphanArticles = Article::query()
            ->where('document_id', $document->id)
            ->whereNull('parent_node_id')
            ->with(['activeVersion', 'versions' => function ($q) {
                $q->orderByDesc('created_at');
            }])
            ->orderBy('ordre_affichage')
            ->get();

        /**
         * Nœuds de structure et articles orphelins partagent la même séquence
         * d'ordre racine (assignée par l'ingestion Python : sort_order pour les
         * nœuds, ordre_affichage pour les orphelins). On les ENTRELACE par cette
         * clé au lieu d'annexer les orphelins à la fin — sinon le préambule
         * (article orphelin, ordre 0) s'afficherait APRÈS les chapitres sur un
         * acte structuré, au lieu d'être en tête.
         */
        $orderedItems = $nodes
            ->map(fn (StructureNode $node): array => [
                'order' => $node->sort_order ?? 0,
                'payload' => (new StructureNodeResource($node))->resolve($request),
            ])
            ->concat($orphanArticles->map(fn (Article $article): array => [
                'order' => $article->ordre_affichage ?? 0,
                'payload' => array_merge(
                    (new ArticleBriefResource($article))->resolve($request),
                    [
                        'parent_id' => null,
                        'type' => 'ARTICLE',
                        'title' => null,
                        'articles' => [],
                    ],
                ),
            ]));

        $tree = $orderedItems
            ->sortBy('order')
            ->pluck('payload')
            ->values()
            ->all();

        return $this->success($tree, 'Structure récupérée avec succès');
    }

    /**
     * Create a new structure node.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_id' => 'required|exists:legal_documents,id',
            'type_unite' => 'required|string',
            'numero' => 'nullable|string',
            'titre' => 'nullable|string',
            'parent_id' => 'nullable|exists:structure_nodes,id',
            'sort_order' => 'nullable|integer',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                $id = (string) Str::uuid();
                $slugId = str_replace('-', '_', $id);

                if (! empty($validated['parent_id'])) {
                    $parent = StructureNode::findOrFail($validated['parent_id']);
                    $treePath = $parent->tree_path.'.'.$slugId;
                } else {
                    $treePath = $slugId;
                }

                $node = StructureNode::create([
                    'id' => $id,
                    'document_id' => $validated['document_id'],
                    'type_unite' => $validated['type_unite'],
                    'numero' => $validated['numero'],
                    'titre' => $validated['titre'],
                    'tree_path' => $treePath,
                    'sort_order' => $validated['sort_order'] ?? 0,
                    'validation_status' => 'draft',
                ]);

                return $this->success(
                    new StructureNodeResource($node),
                    'Nœud créé avec succès',
                    201
                );
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du nœud structure: '.$e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(
                null,
                'Impossible de créer le nœud : '.$e->getMessage(),
                500
            );
        }
    }

    /**
     * Update a structure node.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $node = StructureNode::findOrFail($id);

        $validated = $request->validate([
            'type_unite' => 'sometimes|string',
            'numero' => 'sometimes|nullable|string',
            'titre' => 'sometimes|nullable|string',
            'sort_order' => 'sometimes|integer',
            'validation_status' => 'sometimes|string|in:draft,review,published,validated,error',
        ]);

        $node->update($validated);

        return $this->success(
            new StructureNodeResource($node),
            'Nœud mis à jour avec succès'
        );
    }

    /**
     * Move a structure node (reparent and/or reorder).
     */
    public function move(Request $request, string $id): JsonResponse
    {
        $node = StructureNode::findOrFail($id);

        $validated = $request->validate([
            'parent_id' => 'nullable|exists:structure_nodes,id',
            'sort_order' => 'required|integer',
        ]);

        $oldPath = $node->tree_path;
        $newParentId = $validated['parent_id'];
        $newSortOrder = $validated['sort_order'];

        // Prevent moving to self or descendant
        if ($newParentId) {
            $parent = StructureNode::findOrFail($newParentId);
            if ($parent->tree_path === $oldPath || str_starts_with($parent->tree_path.'.', $oldPath.'.')) {
                return $this->error(null, 'Impossible de déplacer un nœud dans lui-même ou dans l\'un de ses descendants', 422);
            }
        }

        try {
            DB::transaction(function () use ($node, $newParentId, $newSortOrder, $oldPath) {
                // 1. Calculate new path
                $nodeIdSlug = str_replace('-', '_', $node->id);
                if ($newParentId) {
                    $parent = StructureNode::findOrFail($newParentId);
                    $newPath = $parent->tree_path.'.'.$nodeIdSlug;
                } else {
                    $newPath = $nodeIdSlug;
                }

                // 2. Update the node and all its descendants' paths if changed
                if ($oldPath !== $newPath) {
                    $oldPathCount = count(explode('.', $oldPath));

                    DB::statement(
                        "UPDATE structure_nodes
                         SET tree_path = ?::ltree || CASE
                            WHEN nlevel(tree_path) > ? THEN subpath(tree_path, ?)
                            ELSE ''::ltree
                         END
                         WHERE tree_path <@ ?::ltree AND document_id = ?",
                        [$newPath, $oldPathCount, $oldPathCount, $oldPath, $node->document_id]
                    );
                }

                // 3. Shift siblings to make room for the moved node
                // We shift nodes in the TARGET parent that have sort_order >= newSortOrder
                $siblingsQuery = StructureNode::where('document_id', $node->document_id)
                    ->where('id', '!=', $node->id);

                if ($newParentId) {
                    $parent = StructureNode::findOrFail($newParentId);
                    // Match siblings by parent path (nlevel-1)
                    $siblingsQuery->whereRaw('subpath(tree_path, 0, -1) = ?::ltree', [$parent->tree_path])
                        ->whereRaw('nlevel(tree_path) = ?', [count(explode('.', $parent->tree_path)) + 1]);
                } else {
                    // Root nodes have nlevel = 1
                    $siblingsQuery->whereRaw('nlevel(tree_path) = 1');
                }

                $siblingsQuery->where('sort_order', '>=', $newSortOrder)->increment('sort_order');

                // 4. Update the node itself with new sort_order and path
                // Note: tree_path might have been updated by DB::statement, but we update it again to be sure and sync model
                $node->update([
                    'sort_order' => $newSortOrder,
                    'tree_path' => $newPath,
                ]);
            });

            return $this->success(
                new StructureNodeResource($node->fresh()),
                'Nœud déplacé avec succès'
            );
        } catch (\Exception $e) {
            Log::error('Erreur lors du déplacement du nœud: '.$e->getMessage(), [
                'node_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(null, 'Erreur lors du déplacement : '.$e->getMessage(), 500);
        }
    }

    /**
     * Delete a structure node and all its descendants.
     */
    public function destroy(string $id): JsonResponse
    {
        $node = StructureNode::findOrFail($id);

        try {
            DB::transaction(function () use ($node) {
                $path = $node->tree_path;

                // 1. Delete all articles belonging to this node or its descendants
                DB::statement(
                    'DELETE FROM articles
                     WHERE parent_node_id IN (
                         SELECT id FROM structure_nodes
                         WHERE tree_path <@ ?::ltree AND document_id = ?
                     )',
                    [$path, $node->document_id]
                );

                // 2. Delete all structure nodes that are descendants (including self)
                DB::statement(
                    'DELETE FROM structure_nodes
                     WHERE tree_path <@ ?::ltree AND document_id = ?',
                    [$path, $node->document_id]
                );
            });

            return $this->success(null, 'Nœud et ses descendants supprimés avec succès');
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du nœud: '.$e->getMessage());

            return $this->error(null, 'Impossible de supprimer le nœud : '.$e->getMessage(), 500);
        }
    }
}
