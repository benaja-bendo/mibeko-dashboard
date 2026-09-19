<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ArticleResource;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Services\Cdn\CdnPurgeScheduler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ArticleController extends Controller
{
    /**
     * Les articles de même rang qu'un article donné : ceux de la même division,
     * ou — pour un acte court sans structure — ceux rattachés directement au
     * document. `where('parent_node_id', null)` compilerait en `= NULL`, qui
     * n'est jamais vrai en SQL : la fratrie orpheline doit passer par whereNull.
     */
    private static function fratrieDe(string $documentId, ?string $parentNodeId): Builder
    {
        return Article::query()
            ->where('document_id', $documentId)
            ->when(
                $parentNodeId === null,
                fn ($q) => $q->whereNull('parent_node_id'),
                fn ($q) => $q->where('parent_node_id', $parentNodeId),
            );
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $article = Article::with(['versions' => function ($q) {
            $q->orderByDesc('created_at');
        }, 'activeVersion', 'parentNode'])->findOrFail($id);

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
            // Nullable : les actes courts (arrêtés, décrets détachés d'un JO) n'ont
            // aucune division, leurs articles sont rattachés directement au document
            // (parent_node_id NULL) — cf. les « articles orphelins » de l'arbre.
            'parent_node_id' => [
                'nullable',
                Rule::exists('structure_nodes', 'id')->whereNull('deleted_at'),
            ],
            'numero_article' => 'required|string',
            'content' => 'required|string',
            'source_locator' => 'sometimes|array',
            'ordre_affichage' => 'nullable|integer',
            'validation_status' => 'sometimes|string|in:pending,validated,error,draft',
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

                // Insertion à un rang précis : décaler les frères pour libérer la
                // place, sinon deux articles partagent le même ordre d'affichage et
                // leur ordre relatif redevient indéterminé.
                if (isset($validated['ordre_affichage'])) {
                    self::fratrieDe(
                        $validated['document_id'],
                        $validated['parent_node_id'] ?? null
                    )
                        ->where('ordre_affichage', '>=', $validated['ordre_affichage'])
                        ->increment('ordre_affichage');
                }

                $article = Article::create([
                    'document_id' => $validated['document_id'],
                    'parent_node_id' => $validated['parent_node_id'] ?? null,
                    'numero_article' => $validated['numero_article'],
                    'ordre_affichage' => $validated['ordre_affichage'] ?? 0,
                    'validation_status' => $validated['validation_status'] ?? 'pending',
                ]);

                $article->versions()->create([
                    'contenu_texte' => $validated['content'],
                    'source_locator' => $validated['source_locator'] ?? [],
                    'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
                    'validation_status' => $validated['validation_status'] ?? 'pending',
                    'is_verified' => ($validated['validation_status'] ?? null) === 'validated',
                ]);

                return $this->success(
                    new ArticleResource($article->load('activeVersion')),
                    'Article créé avec succès',
                    201
                );
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création de l\'article: '.$e->getMessage());

            return $this->error(null, 'Impossible de créer l\'article. Réessayez ou contactez le support.', 500);
        }
    }

    /**
     * Corrige un article : coquille OCR, découpage, mise en forme — jamais un
     * changement de droit. Décision du 19/09/2026 (dashboard#166) : une
     * correction ne fork JAMAIS de version, quel que soit le jour. Avant
     * cette règle, un contenu changé un jour différent de celui où la
     * version active avait commencé ouvrait une nouvelle ligne
     * `article_versions` — exactement ce qu'un amendement fait, sans qu'un
     * texte modificateur ne soit jamais désigné. Mesuré en production le
     * 19/09 : 2 486 articles avaient ainsi des « versions » dont les dates de
     * début coïncidaient avec des campagnes de réingestion, jamais avec un
     * amendement réel — ce chemin (Laravel, ce contrôleur) n'en produit
     * aucune dans l'audit (`owen-it/auditing`, 0 ligne sur `article_versions`
     * tous événements confondus) : la cause dominante était côté pipeline
     * Python, mais la RÈGLE elle-même — jour différent = nouvelle version —
     * était la même faille, prête à se reproduire ici. Un vrai amendement
     * passe désormais par `addVersion()`, seul chemin qui fork, et qui
     * exige de désigner le texte modificateur.
     *
     * L'historique d'une correction reste traçable : `ArticleVersion` est
     * `Auditable`, une mise à jour EN PLACE laisse une ligne `updated` dans
     * `audits` (qui/quand/avant/après) — pas besoin de forker une période de
     * validité pour ça.
     */
    public function update(Request $request, string $id, CdnPurgeScheduler $cdnPurge): JsonResponse
    {
        $article = Article::findOrFail($id);

        $validated = $request->validate([
            'numero_article' => 'sometimes|string',
            'parent_node_id' => [
                'sometimes',
                Rule::exists('structure_nodes', 'id')->whereNull('deleted_at'),
            ],
            'ordre_affichage' => 'sometimes|integer',
            'validation_status' => 'sometimes|string|in:pending,validated,error,draft',
            'content' => 'sometimes|string', // If provided, updates active version or creates new
            'source_locator' => 'sometimes|array', // To save PDF zone
        ]);

        try {
            return DB::transaction(function () use ($article, $validated, $cdnPurge) {
                // Devient vrai dès que la branche contenu/zone ci-dessous a
                // effectivement écrit `validation_status` dans une VERSION
                // (créée ou mise à jour en place) — pas seulement envoyée par le
                // client. Sert de garde au bloc de repli plus bas.
                $versionHandledStatus = false;

                if (isset($validated['content']) || isset($validated['source_locator'])) {
                    $activeVersion = $article->activeVersion;

                    $contentChanged = isset($validated['content']) && (! $activeVersion || $activeVersion->contenu_texte !== $validated['content']);
                    $locatorChanged = isset($validated['source_locator']) && (! $activeVersion || $activeVersion->source_locator !== $validated['source_locator']);

                    if ($contentChanged || $locatorChanged) {
                        $versionHandledStatus = isset($validated['validation_status']);

                        $updateData = [];
                        if (isset($validated['content'])) {
                            $updateData['contenu_texte'] = $validated['content'];
                        }
                        if (isset($validated['source_locator'])) {
                            $updateData['source_locator'] = $validated['source_locator'];
                        }
                        if (isset($validated['validation_status'])) {
                            $updateData['validation_status'] = $validated['validation_status'];
                        }

                        if ($activeVersion) {
                            // Toujours en place — jamais de fork ici (voir le
                            // docblock de la méthode).
                            $activeVersion->update($updateData);
                        } else {
                            // Aucune version encore ouverte (article jamais
                            // versionné) : on en pose une, ouverte, à
                            // aujourd'hui — c'est un constat de premier état,
                            // pas un amendement.
                            $article->versions()->create([
                                'contenu_texte' => $validated['content'] ?? '',
                                'source_locator' => $validated['source_locator'] ?? [],
                                'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
                                'validation_status' => $validated['validation_status'] ?? 'pending',
                                'is_verified' => false,
                            ]);
                        }
                    }
                }

                // Statut envoyé sans que la branche ci-dessus n'ait touché de
                // version — contenu/zone absents, ou envoyés mais inchangés
                // (l'éditeur complet renvoie toujours le contenu, même non
                // modifié). Sans ce bloc, seule la colonne `articles.validation_status`
                // change (ligne plus bas) : rien que ne relit l'arbre, les exports
                // ou le MCP, qui lisent tous la version active — les boutons de
                // statut semblent alors sans effet.
                if (isset($validated['validation_status']) && ! $versionHandledStatus) {
                    // Requête fraîche, pas la relation mise en cache sur $article :
                    // la branche ci-dessus a pu fermer l'ancienne version active et
                    // en créer une nouvelle, que le cache ne verrait pas.
                    $currentActiveVersion = $article->activeVersion()->first();
                    if ($currentActiveVersion) {
                        $currentActiveVersion->update([
                            'validation_status' => $validated['validation_status'],
                            'is_verified' => $validated['validation_status'] === 'validated',
                        ]);
                    }
                }

                // If parent_node_id or ordre_affichage changes, shift siblings
                if (isset($validated['parent_node_id']) || isset($validated['ordre_affichage'])) {
                    $newParentId = $validated['parent_node_id'] ?? $article->parent_node_id;
                    $newOrder = $validated['ordre_affichage'] ?? $article->ordre_affichage;

                    self::fratrieDe($article->document_id, $newParentId)
                        ->where('id', '!=', $article->id)
                        ->where('ordre_affichage', '>=', $newOrder)
                        ->increment('ordre_affichage');
                }

                $article->update(collect($validated)->except('content')->toArray());

                // Purge CDN (dashboard#161) : le texte d'un article publié
                // vient de changer — la page publique du document et celle de
                // l'article servent une version périmée tant que le cache
                // n'est pas vidé. `$contentChanged`/`$locatorChanged`
                // n'existent que si le bloc contenu/zone ci-dessus s'est
                // exécuté ; sur un document en brouillon, rien ne sert au
                // public, donc rien à purger.
                $documentEstPublie = LegalDocument::where('id', $article->document_id)
                    ->where('curation_status', LegalDocument::STATUS_PUBLISHED)
                    ->exists();

                if ($documentEstPublie && ((isset($contentChanged) && $contentChanged) || (isset($locatorChanged) && $locatorChanged))) {
                    $cdnPurge->scheduleAsync();
                }

                return $this->success(
                    new ArticleResource($article->load('activeVersion')),
                    'Article mis à jour avec succès'
                );
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour de l\'article: '.$e->getMessage(), [
                'article_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error(null, 'Erreur lors de la mise à jour de l\'article. Réessayez ou contactez le support.', 500);
        }
    }

    /**
     * Enregistre un AMENDEMENT : un texte identifié a réellement modifié cet
     * article à une date de droit donnée (dashboard#166, décision du
     * 19/09/2026 — deux actions distinctes dans l'UI, texte modificateur
     * obligatoire pour celle-ci). C'est le seul chemin qui fork une nouvelle
     * période de validité ; une simple correction passe par `update()` et ne
     * fork jamais.
     *
     * `modifie_par_document_id` est OBLIGATOIRE : un amendement sans texte
     * désigné est indiscernable après coup d'une correction mal aiguillée —
     * exactement le défaut mesuré le 19/09 (0 ligne renseignée sur 37 594
     * `article_versions`).
     *
     * Volontairement limité à l'AJOUT EN FIN DE CHRONOLOGIE : `start_date`
     * doit être postérieure ou égale au début de la version la plus
     * récente. Insérer un amendement entre deux versions déjà connues
     * demanderait de scinder un intervalle existant — un cas réel, mais
     * hors du périmètre de cette évolution ; il échoue en 422 plutôt que de
     * choisir arbitrairement laquelle des versions chevauchantes fermer.
     */
    public function addVersion(Request $request, string $id): JsonResponse
    {
        $article = Article::findOrFail($id);

        $validated = $request->validate([
            'content' => 'required|string',
            'start_date' => 'required|date',
            'modifie_par_document_id' => [
                'required',
                Rule::exists('legal_documents', 'id')->whereNull('deleted_at'),
            ],
            'validation_status' => 'sometimes|string|in:pending,validated,error,draft',
        ]);

        try {
            return DB::transaction(function () use ($article, $validated) {
                $startDate = $validated['start_date'];

                $derniereVersion = ArticleVersion::where('article_id', $article->id)
                    ->orderByRaw('lower(validity_period) desc')
                    ->first();

                if ($derniereVersion !== null) {
                    $anterieure = ArticleVersion::where('id', $derniereVersion->id)
                        ->whereRaw('lower(validity_period) > ?::date', [$startDate])
                        ->exists();

                    if ($anterieure) {
                        return $this->error(
                            null,
                            'Cette date d\'effet précède la version la plus récente déjà enregistrée : '
                            .'seul l\'ajout d\'un amendement après la dernière version connue est pris en charge.',
                            422,
                        );
                    }
                }

                // 1. Ferme toute version qui chevaucherait la nouvelle, ouverte
                // à `start_date` — dans le cas append-only garanti ci-dessus,
                // c'est au plus la version active.
                $overlappingVersion = ArticleVersion::where('article_id', $article->id)
                    ->whereRaw('validity_period && daterange(?::date, null)', [$startDate])
                    ->first();

                if ($overlappingVersion) {
                    $startedSameDay = ArticleVersion::where('id', $overlappingVersion->id)
                        ->whereRaw('lower(validity_period) = ?::date', [$startDate])
                        ->exists();

                    if ($startedSameDay) {
                        // Même date d'effet : corrige l'amendement qu'on vient
                        // d'enregistrer plutôt que d'en empiler un doublon.
                        $overlappingVersion->update([
                            'contenu_texte' => $validated['content'],
                            'modifie_par_document_id' => $validated['modifie_par_document_id'],
                            'validation_status' => $validated['validation_status'] ?? $overlappingVersion->validation_status,
                        ]);
                    } else {
                        // Ferme la version active à la date d'effet (exclusive).
                        // Binding paramétré : jamais de date interpolée dans le SQL brut.
                        DB::update(
                            'UPDATE article_versions
                             SET validity_period = daterange(lower(validity_period), ?::date)
                             WHERE id = ?',
                            [$startDate, $overlappingVersion->id]
                        );

                        $article->versions()->create([
                            'contenu_texte' => $validated['content'],
                            'modifie_par_document_id' => $validated['modifie_par_document_id'],
                            'validity_period' => ArticleVersion::makeValidityPeriod($startDate),
                            'validation_status' => $validated['validation_status'] ?? 'pending',
                            'is_verified' => true,
                        ]);
                    }
                } else {
                    // Premier amendement de l'article : rien à fermer.
                    $article->versions()->create([
                        'contenu_texte' => $validated['content'],
                        'modifie_par_document_id' => $validated['modifie_par_document_id'],
                        'validity_period' => ArticleVersion::makeValidityPeriod($startDate),
                        'validation_status' => $validated['validation_status'] ?? 'pending',
                        'is_verified' => true,
                    ]);
                }

                return $this->success(
                    new ArticleResource($article->load('versions')),
                    'Amendement enregistré avec succès'
                );
            });
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'enregistrement de l\'amendement: '.$e->getMessage());

            return $this->error(null, 'Erreur lors de l\'enregistrement de l\'amendement. Réessayez ou contactez le support.', 500);
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
