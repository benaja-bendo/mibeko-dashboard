<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ArticleResource;
use App\Models\Article;
use App\Models\ArticleVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArticleController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $article = Article::with(['versions' => function ($q) {
            $q->orderByDesc('created_at');
        }, 'parentNode'])->findOrFail($id);

        return $this->success(
            new ArticleResource($article),
            'Article récupéré avec succès'
        );
    }

    /**
     * Create a new article.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_id' => 'required|exists:legal_documents,id',
            'parent_node_id' => 'required|exists:structure_nodes,id',
            'numero_article' => 'required|string',
            'content' => 'required|string',
            'ordre_affichage' => 'nullable|integer',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                // Check for duplicate number in same document
                $exists = Article::where('document_id', $validated['document_id'])
                    ->where('numero_article', $validated['numero_article'])
                    ->exists();

                if ($exists) {
                    return $this->error(
                        ['numero_article' => ['Cet article existe déjà dans ce document.']],
                        'Conflit de numéro d\'article',
                        422
                    );
                }

                $article = Article::create([
                    'document_id' => $validated['document_id'],
                    'parent_node_id' => $validated['parent_node_id'],
                    'numero_article' => $validated['numero_article'],
                    'ordre_affichage' => $validated['ordre_affichage'] ?? 0,
                    'validation_status' => 'pending',
                ]);

                $article->versions()->create([
                    'contenu_texte' => $validated['content'],
                    'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
                    'validation_status' => 'pending',
                    'is_verified' => false,
                ]);

                return $this->success(
                    new ArticleResource($article->load('activeVersion')),
                    'Article créé avec succès',
                    201
                );
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de l\'article: '.$e->getMessage());

            return $this->error(null, 'Impossible de créer l\'article : '.$e->getMessage(), 500);
        }
    }

    /**
     * Update an article.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $article = Article::findOrFail($id);

        $validated = $request->validate([
            'numero_article' => 'sometimes|string',
            'parent_node_id' => 'sometimes|exists:structure_nodes,id',
            'ordre_affichage' => 'sometimes|integer',
            'validation_status' => 'sometimes|string|in:pending,validated,error,draft',
            'content' => 'sometimes|string', // If provided, updates active version or creates new
            'source_locator' => 'sometimes|array', // To save PDF zone
        ]);

        try {
            return DB::transaction(function () use ($article, $validated) {
                if (isset($validated['content']) || isset($validated['source_locator'])) {
                    $today = now()->toDateString();
                    $activeVersion = $article->activeVersion;

                    $contentChanged = isset($validated['content']) && (!$activeVersion || $activeVersion->contenu_texte !== $validated['content']);
                    $locatorChanged = isset($validated['source_locator']) && (!$activeVersion || $activeVersion->source_locator !== $validated['source_locator']);

                    if ($contentChanged || $locatorChanged) {
                        // Find any version that would overlap with a new version starting today [today, infinity)
                        $overlappingVersion = ArticleVersion::where('article_id', $article->id)
                            ->whereRaw('validity_period && daterange(?::date, null)', [$today])
                            ->first();

                        if ($overlappingVersion) {
                            // Check if the overlapping version started exactly today
                            $startedToday = ArticleVersion::where('id', $overlappingVersion->id)
                                ->whereRaw('lower(validity_period) = ?::date', [$today])
                                ->exists();

                            if ($startedToday) {
                                // Update in place if it's the same day
                                $updateData = [];
                                if (isset($validated['content'])) $updateData['contenu_texte'] = $validated['content'];
                                if (isset($validated['source_locator'])) $updateData['source_locator'] = $validated['source_locator'];
                                if (isset($validated['validation_status'])) $updateData['validation_status'] = $validated['validation_status'];
                                
                                $overlappingVersion->update($updateData);
                            } else {
                                // Close the overlapping version (it must have started before today)
                                DB::table('article_versions')
                                    ->where('id', $overlappingVersion->id)
                                    ->update([
                                        'validity_period' => DB::raw("daterange(lower(validity_period), '$today')"),
                                    ]);

                                // Create new version starting today
                                $article->versions()->create([
                                    'contenu_texte' => $validated['content'] ?? ($activeVersion?->contenu_texte ?? ''),
                                    'source_locator' => $validated['source_locator'] ?? ($activeVersion?->source_locator ?? []),
                                    'validity_period' => ArticleVersion::makeValidityPeriod($today),
                                    'validation_status' => $validated['validation_status'] ?? 'pending',
                                    'is_verified' => false,
                                ]);
                            }
                        } else {
                            // No overlapping version found, create a new one
                            $article->versions()->create([
                                'contenu_texte' => $validated['content'] ?? ($activeVersion?->contenu_texte ?? ''),
                                'source_locator' => $validated['source_locator'] ?? ($activeVersion?->source_locator ?? []),
                                'validity_period' => ArticleVersion::makeValidityPeriod($today),
                                'validation_status' => $validated['validation_status'] ?? 'pending',
                                'is_verified' => false,
                            ]);
                        }
                    }
                }

                // If parent_node_id or ordre_affichage changes, shift siblings
                if (isset($validated['parent_node_id']) || isset($validated['ordre_affichage'])) {
                    $newParentId = $validated['parent_node_id'] ?? $article->parent_node_id;
                    $newOrder = $validated['ordre_affichage'] ?? $article->ordre_affichage;

                    Article::where('parent_node_id', $newParentId)
                        ->where('id', '!=', $article->id)
                        ->where('ordre_affichage', '>=', $newOrder)
                        ->increment('ordre_affichage');
                }

                $article->update(collect($validated)->except('content')->toArray());

                return $this->success(
                    new ArticleResource($article->load('activeVersion')),
                    'Article mis à jour avec succès'
                );
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de l\'article: '.$e->getMessage(), [
                'article_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error(null, 'Erreur lors de la mise à jour : '.$e->getMessage(), 500);
        }
    }

    /**
     * Add a new version to an article.
     */
    public function addVersion(Request $request, string $id): JsonResponse
    {
        $article = Article::findOrFail($id);

        $validated = $request->validate([
            'content' => 'required|string',
            'start_date' => 'required|date',
            'validation_status' => 'sometimes|string|in:pending,validated,error,draft',
        ]);

        try {
            return DB::transaction(function () use ($article, $validated) {
                $startDate = $validated['start_date'];

                // 1. Close any existing version that overlaps with the new start date
                // We find the active version (the one where the start date is before or equal to the new version's start date
                // and whose end date is null or after the new start date)
                $overlappingVersion = ArticleVersion::where('article_id', $article->id)
                    ->whereRaw('validity_period && daterange(?::date, null)', [$startDate])
                    ->first();

                if ($overlappingVersion) {
                    $startedSameDay = ArticleVersion::where('id', $overlappingVersion->id)
                        ->whereRaw('lower(validity_period) = ?::date', [$startDate])
                        ->exists();

                    if ($startedSameDay) {
                        // Update in place if it's the same day
                        $overlappingVersion->update([
                            'contenu_texte' => $validated['content'],
                            'validation_status' => $validated['validation_status'] ?? $overlappingVersion->validation_status,
                        ]);
                    } else {
                        // Close it at the new start date (exclusive)
                        DB::table('article_versions')
                            ->where('id', $overlappingVersion->id)
                            ->update([
                                'validity_period' => DB::raw("daterange(lower(validity_period), '$startDate')"),
                            ]);

                        // Create new one
                        $article->versions()->create([
                            'contenu_texte' => $validated['content'],
                            'validity_period' => ArticleVersion::makeValidityPeriod($startDate),
                            'validation_status' => $validated['validation_status'] ?? 'pending',
                            'is_verified' => true,
                        ]);
                    }
                } else {
                    // Just create if no overlap
                    $article->versions()->create([
                        'contenu_texte' => $validated['content'],
                        'validity_period' => ArticleVersion::makeValidityPeriod($startDate),
                        'validation_status' => $validated['validation_status'] ?? 'pending',
                        'is_verified' => true,
                    ]);
                }

                return $this->success(
                    new ArticleResource($article->load('versions')),
                    'Nouvelle version ajoutée avec succès'
                );
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'ajout de version: '.$e->getMessage());
            return $this->error(null, 'Erreur lors de l\'ajout de version : '.$e->getMessage(), 500);
        }
    }

    /**
     * Delete an article.
     */
    public function destroy(string $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $article->delete();

        return $this->success(null, 'Article supprimé avec succès');
    }
}
