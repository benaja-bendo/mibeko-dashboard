<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\ThemeClassifier;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\LegalDocumentResource;
use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\DocumentRelation;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use App\Models\Tag;
use App\Services\DocumentDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @group Legal Documents
 *
 * API endpoints for managing legal documents.
 */
class LegalDocumentController extends Controller
{
    /** Shared filter definitions used across index and search. */
    private function allowedFilters(): array
    {
        return [
            AllowedFilter::partial('titre_officiel'),
            'type_code',
            'institution_id',
            'official_journal_id',
            'statut',
            'curation_status',
            'document_role',
            'legal_scope',
            AllowedFilter::callback('date_from', function ($query, $value) {
                $query->whereDate('date_publication', '>=', $value);
            }),
            AllowedFilter::callback('date_to', function ($query, $value) {
                $query->whereDate('date_publication', '<=', $value);
            }),
            AllowedFilter::callback('recent', function ($query, $value) {
                // ?filter[recent]=7  → modifiés dans les 7 derniers jours
                $days = is_numeric($value) ? (int) $value : 7;
                $query->where('updated_at', '>=', now()->subDays($days));
            }),
        ];
    }

    /** Shared sort definitions. */
    private function allowedSorts(): array
    {
        return ['titre_officiel', 'date_signature', 'date_publication', 'created_at', 'updated_at', 'curation_status', 'statut'];
    }

    /**
     * List legal documents.
     *
     * @queryParam filter[titre_officiel] string Filter by partial official title.
     * @queryParam filter[type_code] string Filter by document type code (e.g., "LOI", "CODE").
     * @queryParam filter[institution_id] string UUID of the institution.
     * @queryParam filter[curation_status] string Filter by curation status.
     * @queryParam filter[statut] string Filter by validity status.
     * @queryParam filter[recent] int Filter documents modified in the last N days.
     * @queryParam per_page int Number of items per page (default 20, max 100).
     * @queryParam sort string Sort field (e.g., "titre_officiel", "-updated_at").
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $documents = QueryBuilder::for(LegalDocument::class)
            ->with(['institution', 'type'])
            ->withCount([
                'articles',
                'relations',
                'tags',
                'articles as embedded_articles_count' => fn ($q) => $q->whereHas(
                    'activeVersion',
                    fn ($q2) => $q2->whereNotNull('embedding')
                ),
            ])
            ->allowedFilters($this->allowedFilters())
            ->allowedSorts($this->allowedSorts())
            ->latest('updated_at')
            ->paginate($perPage);

        $this->attachEmbeddingProgress($documents);

        return $this->paginatedSuccess(
            $documents,
            LegalDocumentResource::class,
            'Documents récupérés avec succès'
        );
    }

    /**
     * Search legal documents (full-text on titre_officiel + reference_nor).
     *
     * @queryParam q string Search query.
     * @queryParam per_page int Number of items per page (default 20, max 100).
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $perPage = min((int) $request->input('per_page', 20), 100);

        $documents = QueryBuilder::for(LegalDocument::class)
            ->with(['institution', 'type'])
            ->withCount([
                'articles',
                'relations',
                'tags',
                'articles as embedded_articles_count' => fn ($q) => $q->whereHas(
                    'activeVersion',
                    fn ($q2) => $q2->whereNotNull('embedding')
                ),
            ])
            ->when(! empty($query), function ($q) use ($query) {
                $q->where(function ($sub) use ($query) {
                    $sub->where('titre_officiel', 'ilike', "%{$query}%")
                        ->orWhere('reference_nor', 'ilike', "%{$query}%")
                        ->orWhere('stock_code', 'ilike', "%{$query}%");
                });
            })
            ->allowedFilters($this->allowedFilters())
            ->allowedSorts($this->allowedSorts())
            ->latest('updated_at')
            ->paginate($perPage);

        $this->attachEmbeddingProgress($documents);

        return $this->paginatedSuccess(
            $documents,
            LegalDocumentResource::class,
            'Résultats de la recherche de documents'
        );
    }

    /**
     * Marque les documents d'une page dont l'indexation des embeddings est en cours.
     *
     * Une seule requête sur job_batches : un lot nommé « embed-doc:{id} » non
     * terminé et non annulé signifie que le document est en cours d'indexation.
     */
    private function attachEmbeddingProgress(LengthAwarePaginator $paginator): void
    {
        $documents = $paginator->getCollection();

        if ($documents->isEmpty()) {
            return;
        }

        $activeNames = DB::table('job_batches')
            ->whereIn('name', $documents->map(fn ($doc) => "embed-doc:{$doc->id}"))
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->pluck('name')
            ->flip();

        $documents->each(function ($doc) use ($activeNames) {
            $doc->embedding_in_progress = $activeNames->has("embed-doc:{$doc->id}");
        });
    }

    /**
     * Get a legal document.
     *
     * Returns a single legal document with its articles, relations, and their latest versions.
     */
    public function show(string $id): JsonResponse
    {
        $document = QueryBuilder::for(LegalDocument::class)
            ->with(['institution', 'type', 'officialJournal', 'articles.latestVersion', 'relations.targetDocument', 'tags'])
            ->withCount([
                'articles',
                'relations',
                'tags',
                'articles as embedded_articles_count' => fn ($q) => $q->whereHas(
                    'activeVersion',
                    fn ($q2) => $q2->whereNotNull('embedding')
                ),
            ])
            ->findOrFail($id);

        return $this->success(
            new LegalDocumentResource($document),
            'Document récupéré avec succès'
        );
    }

    /**
     * Public, slug-addressed view of a published document for the marketing site.
     *
     * Serves `/codes/{slug}` (and `?article={numero}`) for SEO pages. Only
     * published documents are reachable so drafts never get indexed. Returns
     * the document metadata, a lightweight index of every article (numbers
     * only, for navigation and sitemaps) and, when requested, the full text of
     * a single article — keeping the page payload small even on large codes.
     */
    public function showBySlug(Request $request, string $slug): JsonResponse
    {
        $document = LegalDocument::query()
            ->published()
            ->where('slug', $slug)
            ->with(['institution', 'type', 'officialJournal', 'tags'])
            ->firstOrFail();

        $articles = $document->articles()
            ->orderBy('ordre_affichage')
            ->get(['id', 'document_id', 'numero_article', 'ordre_affichage'])
            ->map(fn (Article $article) => [
                'id' => $article->id,
                'number' => $article->numero_article,
                'order' => $article->ordre_affichage,
            ])
            ->values();

        $currentArticle = null;

        if ($request->filled('article')) {
            $article = $document->articles()
                ->where('numero_article', $request->string('article'))
                ->with('activeVersion')
                ->first();

            if ($article) {
                $currentArticle = [
                    'id' => $article->id,
                    'number' => $article->numero_article,
                    'order' => $article->ordre_affichage,
                    'content' => $article->activeVersion?->contenu_texte,
                    'related' => $this->relatedTexts($article),
                ];
            }
        }

        // PDF d'origine disponible ? (média PDF du document, ou à défaut le PDF
        // du Journal Officiel dont l'acte FLUX a été extrait — cf. PdfProxyController).
        $document->loadMissing('mediaFiles');
        $hasPdf = $document->mediaFiles->contains(
            fn ($file) => $file->mime_type === 'application/pdf'
                || str_ends_with(strtolower((string) $file->file_path), '.pdf')
        ) || (bool) $document->officialJournal?->file_path;

        return $this->success([
            'document' => new LegalDocumentResource($document),
            'articles' => $articles,
            // Sommaire hiérarchique pour la navigation du site vitrine. Sans le
            // texte des articles (poids maîtrisé sur les gros codes) : la lecture
            // se fait sur la page d'article. Sûr par construction — le document
            // est déjà filtré sur `published()`, donc rien d'inédit ne fuite.
            'structure' => $this->publicStructure($document),
            'has_pdf' => $hasPdf,
            'current_article' => $currentArticle,
        ], 'Document public récupéré avec succès');
    }

    /**
     * Sommaire public d'un document : nœuds de structure (aplatis, avec
     * `parent_id` dérivé du `tree_path`) et articles racine (orphelins). Le
     * site vitrine reconstruit l'arbre côté client. Aucun contenu d'article
     * n'est exposé ici (seulement les numéros, pour le maillage de navigation).
     *
     * @return array{nodes: array<int, array<string, mixed>>, orphan_articles: array<int, array<string, mixed>>}
     */
    private function publicStructure(LegalDocument $document): array
    {
        $articlesByNode = $document->articles()
            ->orderBy('ordre_affichage')
            ->get(['id', 'numero_article', 'ordre_affichage', 'parent_node_id'])
            ->groupBy('parent_node_id');

        $rawNodes = StructureNode::query()
            ->where('document_id', $document->id)
            ->orderBy('sort_order')
            ->get(['id', 'type_unite', 'numero', 'titre', 'tree_path', 'sort_order']);

        // Le `tree_path` (ltree) encode chaque nœud par un label `n_<uuid>`.
        // Plutôt que de reconvertir le label en uuid (fragile : préfixe `n_`,
        // séparateurs `_`), on retrouve le parent en faisant correspondre son
        // `tree_path` (chemin courant amputé du dernier segment).
        $idByPath = $rawNodes->pluck('id', 'tree_path');

        $nodes = $rawNodes
            ->map(function (StructureNode $node) use ($articlesByNode, $idByPath): array {
                $parts = explode('.', (string) $node->tree_path);
                $parentId = null;
                if (count($parts) > 1) {
                    array_pop($parts);
                    $parentId = $idByPath[implode('.', $parts)] ?? null;
                }

                return [
                    'id' => $node->id,
                    'parent_id' => $parentId,
                    'type' => $node->type_unite,
                    'number' => $node->numero,
                    'title' => $node->titre,
                    'order' => $node->sort_order ?? 0,
                    'articles' => ($articlesByNode[$node->id] ?? collect())
                        ->map(fn (Article $article): array => [
                            'number' => $article->numero_article,
                            'order' => $article->ordre_affichage ?? 0,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $orphanArticles = ($articlesByNode[null] ?? collect())
            ->map(fn (Article $article): array => [
                'number' => $article->numero_article,
                'order' => $article->ordre_affichage ?? 0,
            ])
            ->values()
            ->all();

        return ['nodes' => $nodes, 'orphan_articles' => $orphanArticles];
    }

    /**
     * Cross-references publiques d'un article (maillage interne SEO).
     *
     * Pour chaque relation de l'article, on renvoie « l'autre bout » (le texte
     * cité/modifiant/abrogeant), **uniquement s'il est publié et a un slug** —
     * on ne crée jamais de lien vers une page inexistante. Le numéro d'article
     * cible peut être absent (relation au niveau document).
     *
     * @return array<int, array<string, mixed>>
     */
    private function relatedTexts(Article $article): array
    {
        return DocumentRelation::query()
            ->where(fn ($query) => $query
                ->where('source_article_id', $article->id)
                ->orWhere('target_article_id', $article->id))
            ->with(['sourceDocument', 'targetDocument', 'sourceArticle', 'targetArticle'])
            ->get()
            ->map(function (DocumentRelation $relation) use ($article) {
                $isSource = $relation->source_article_id === $article->id;
                $otherDocument = $isSource ? $relation->targetDocument : $relation->sourceDocument;
                $otherArticle = $isSource ? $relation->targetArticle : $relation->sourceArticle;

                if (! $otherDocument
                    || $otherDocument->curation_status !== LegalDocument::STATUS_PUBLISHED
                    || empty($otherDocument->slug)) {
                    return null;
                }

                return [
                    'type' => $relation->relation_type,
                    'document_slug' => $otherDocument->slug,
                    'document_title' => $otherDocument->titre_officiel,
                    'article_number' => $otherArticle?->numero_article,
                    'comment' => $relation->commentaire,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Create a legal document manually (editor + admin only).
     *
     * Used to handle extraction gaps: when the pipeline missed a text inside
     * an official journal, an editor can create it here (optionally attached
     * to the journal) and then structure it manually in the viewer.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', LegalDocument::class);

        $validated = $request->validate([
            'titre_officiel' => ['required', 'string', 'max:500'],
            'type_code' => ['sometimes', 'nullable', 'string', 'exists:document_types,code'],
            'official_journal_id' => ['sometimes', 'nullable', 'exists:official_journals,id'],
            'reference_nor' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_signature' => ['sometimes', 'nullable', 'date'],
            'date_publication' => ['sometimes', 'nullable', 'date'],
            'date_entree_vigueur' => ['sometimes', 'nullable', 'date'],
            'statut' => ['sometimes', 'string', 'in:vigueur,abroge,projet'],
            'legal_scope' => ['sometimes', 'string', Rule::in(LegalDocument::LEGAL_SCOPES)],
        ]);

        $document = LegalDocument::create([
            ...$validated,
            'statut' => $validated['statut'] ?? 'vigueur',
            'legal_scope' => $validated['legal_scope'] ?? LegalDocument::SCOPE_NATIONAL,
            // Toujours FLUX : la contrainte chk_legal_documents_role_logic
            // réserve STOCK aux consolidations du pipeline (stock_code +
            // consolidation_as_of obligatoires, jamais rattachées à un JO).
            'document_role' => 'FLUX',
            'curation_status' => LegalDocument::STATUS_DRAFT,
            'extraction_status' => 'completed',
            'metadata' => ['source' => 'manual'],
        ]);

        return $this->success(
            new LegalDocumentResource($document->load(['institution', 'type'])),
            'Document créé avec succès',
            201
        );
    }

    /**
     * Update a legal document (editor + admin only).
     *
     * Edits the document metadata (title, NOR reference, dates, legal status,
     * scope, type) and the curation workflow status. Publishing requires the
     * document to have at least one article.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $document = LegalDocument::findOrFail($id);

        Gate::authorize('update', $document);

        $validated = $request->validate([
            'titre_officiel' => ['sometimes', 'string', 'max:500'],
            'reference_nor' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_signature' => ['sometimes', 'nullable', 'date'],
            'date_publication' => ['sometimes', 'nullable', 'date'],
            'date_entree_vigueur' => ['sometimes', 'nullable', 'date'],
            'statut' => ['sometimes', 'string', 'in:vigueur,abroge,projet'],
            'legal_scope' => ['sometimes', 'string', Rule::in(LegalDocument::LEGAL_SCOPES)],
            'type_code' => ['sometimes', 'string', 'exists:document_types,code'],
            // Rattachement / détachement d'un journal officiel
            'official_journal_id' => ['sometimes', 'nullable', 'exists:official_journals,id'],
            'curation_status' => ['sometimes', 'string', Rule::in([
                LegalDocument::STATUS_DRAFT,
                LegalDocument::STATUS_REVIEW,
                LegalDocument::STATUS_VALIDATED,
                LegalDocument::STATUS_PUBLISHED,
            ])],
            // Publication forcée : l'éditeur assume la publication malgré des
            // anomalies de curation bloquantes non résolues (« publier quand même »).
            'force' => ['sometimes', 'boolean'],
            // Thèmes (taxonomie « tags » réutilisée) — tableau d'ids de tags.
            'themes' => ['sometimes', 'array'],
            'themes.*' => ['string', 'exists:tags,id'],
        ]);

        $isPublishing = ($validated['curation_status'] ?? null) === LegalDocument::STATUS_PUBLISHED;
        $force = $request->boolean('force');

        if ($isPublishing && ! $document->articles()->exists()) {
            return $this->error(
                ['curation_status' => ['Un document sans article ne peut pas être publié.']],
                'Impossible de publier un document sans article.',
                422
            );
        }

        // Garde-fou qualité : un document conservant des anomalies de curation
        // BLOQUANTES non résolues (trous/doublons de numérotation, contenu perdu…)
        // ne doit pas atteindre le catalogue publié. Les anomalies `warning`/`info`
        // informent l'éditeur sans empêcher la publication. (Les lignes antérieures
        // sans `severity` sont traitées comme bloquantes pour préserver le comportement.)
        // `force` permet à l'éditeur d'outrepasser ce garde-fou en connaissance de cause.
        if ($isPublishing && ! $force) {
            $blockingFlags = $document->curationFlags()
                ->where('resolved', false)
                ->where(function ($q) {
                    $q->where('severity', CurationFlag::SEVERITY_BLOCKING)
                        ->orWhereNull('severity');
                })
                ->count();

            if ($blockingFlags > 0) {
                return $this->error(
                    ['curation_status' => ["Ce document a {$blockingFlags} anomalie(s) bloquante(s) non résolue(s). Résolvez-les avant de publier, ou utilisez « Publier quand même »."]],
                    'Impossible de publier un document avec des anomalies de curation bloquantes non résolues.',
                    422
                );
            }
        }

        // Publication forcée malgré des anomalies bloquantes : on trace la décision
        // de l'éditeur pour garder un fil d'audit (le contenu peut être imparfait).
        if ($isPublishing && $force) {
            Log::warning('Publication forcée d\'un document malgré le garde-fou de curation.', [
                'document_id' => $document->id,
                'user_id' => $request->user()?->id,
            ]);
        }

        // La contrainte chk_legal_documents_role_logic interdit le
        // rattachement d'un document STOCK (consolidé) à un journal officiel.
        if (! empty($validated['official_journal_id']) && $document->document_role === 'STOCK') {
            return $this->error(
                ['official_journal_id' => ['Un document consolidé (STOCK) ne peut pas être rattaché à un journal officiel.']],
                'Impossible de rattacher un document consolidé à un journal officiel.',
                422
            );
        }

        $document->update(Arr::except($validated, ['themes', 'force']));

        if (array_key_exists('themes', $validated)) {
            $themeIds = $validated['themes'];
            $document->tags()->sync($themeIds);
            $this->propagateThemesToArticles($document, $themeIds);

            // Les compteurs de la bande « Parcourir par thème » sont mis en cache :
            // on les invalide pour refléter le rattachement immédiatement.
            Cache::forget('library:themes');
            Cache::forget('library:home');
        }

        return $this->success(
            new LegalDocumentResource($document->fresh(['institution', 'type', 'tags'])),
            'Document mis à jour avec succès'
        );
    }

    /**
     * Propage les thèmes d'un document à tous ses articles.
     *
     * Les thèmes sont assignés au niveau document (ergonomique pour l'éditeur) ;
     * cette propagation alimente la table `taggables` côté articles pour que la
     * recherche article par slug existante fonctionne aussi. Implémentée en
     * 2 requêtes (purge + insertion groupée) quel que soit le nombre d'articles.
     *
     * @param  array<int, string>  $themeIds
     */
    private function propagateThemesToArticles(LegalDocument $document, array $themeIds): void
    {
        $articleIds = $document->articles()->pluck('id');

        if ($articleIds->isEmpty()) {
            return;
        }

        DB::table('taggables')
            ->where('taggable_type', Article::class)
            ->whereIn('taggable_id', $articleIds)
            ->delete();

        if (empty($themeIds)) {
            return;
        }

        $rows = [];
        foreach ($articleIds as $articleId) {
            foreach ($themeIds as $themeId) {
                $rows[] = [
                    'tag_id' => $themeId,
                    'taggable_id' => $articleId,
                    'taggable_type' => Article::class,
                    'created_at' => now(),
                ];
            }
        }

        DB::table('taggables')->insert($rows);
    }

    /**
     * Suggère des thèmes pour un document via l'IA (assistance à la curation).
     *
     * Lit le titre + un extrait des premiers articles, demande au classifieur
     * 1-3 thèmes dans la taxonomie, et renvoie les tags correspondants. Aucune
     * écriture : l'éditeur valide ensuite via `update`.
     */
    public function suggestThemes(string $id): JsonResponse
    {
        $document = LegalDocument::with(['articles' => fn ($q) => $q->orderBy('ordre_affichage')->limit(15)->with('latestVersion')])
            ->findOrFail($id);

        Gate::authorize('update', $document);

        $excerpt = $document->articles
            ->map(fn ($article) => 'Article '.$article->numero_article.' : '.optional($article->latestVersion)->contenu_texte)
            ->filter()
            ->implode("\n\n");

        $slugs = (new ThemeClassifier)->suggest($document->titre_officiel ?? '', $excerpt);

        $themes = Tag::whereIn('slug', $slugs)
            ->orderBy('display_order')
            ->get(['id', 'name', 'slug', 'icon']);

        return $this->success($themes, 'Thèmes suggérés par l\'IA');
    }

    public function destroy(Request $request, string $id, DocumentDeletionService $deletion): JsonResponse
    {
        $document = LegalDocument::withTrashed()->findOrFail($id);
        $force = $request->boolean('force');

        if ($force) {
            Gate::authorize('forceDelete', $document);
        } else {
            Gate::authorize('delete', $document);
        }

        if ($force) {
            $deletion->forceDelete($document);
        } else {
            $document->delete();
        }

        $message = $force ? 'Document supprimé définitivement avec succès' : 'Document supprimé avec succès';

        return $this->success(null, $message);
    }

    /**
     * Récapitule l'impact d'une suppression définitive (compteurs + garde-fous).
     *
     * Alimente la modale de confirmation côté éditeur : combien de divisions,
     * articles, versions, anomalies, médias et relations disparaîtront, et si le
     * document est cité ailleurs ou enregistré dans des dossiers utilisateurs.
     */
    public function deletionImpact(string $id, DocumentDeletionService $deletion): JsonResponse
    {
        $document = LegalDocument::withTrashed()->findOrFail($id);
        Gate::authorize('forceDelete', $document);

        return $this->success($deletion->impact($document), 'Impact de la suppression calculé');
    }

    /**
     * Bulk delete documents.
     *
     * @bodyParam ids string[] required List of document UUIDs.
     * @bodyParam force boolean Whether to force delete.
     */
    public function bulkDestroy(Request $request, DocumentDeletionService $deletion): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['uuid'],
            'force' => ['boolean'],
        ]);

        $force = $request->boolean('force');
        $ids = $request->input('ids');
        $documents = LegalDocument::withTrashed()->whereIn('id', $ids)->get();

        foreach ($documents as $document) {
            if ($force) {
                Gate::authorize('forceDelete', $document);
            } else {
                Gate::authorize('delete', $document);
            }
        }

        foreach ($documents as $document) {
            if ($force) {
                $deletion->forceDelete($document);
            } else {
                $document->delete();
            }
        }

        $message = $force ? 'Documents supprimés définitivement avec succès' : 'Documents supprimés avec succès';

        return $this->success(['deleted_count' => count($ids)], $message);
    }

    /**
     * Bulk update documents (editor + admin only).
     *
     * @bodyParam ids string[] required List of document UUIDs.
     * @bodyParam action string required Action to perform: set_curation_status, set_statut.
     * @bodyParam value string required New value for the action.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['uuid'],
            'action' => ['required', 'string', 'in:set_curation_status,set_statut'],
            'value' => ['required', 'string'],
        ]);

        $allowedCurationStatuses = [
            LegalDocument::STATUS_DRAFT,
            LegalDocument::STATUS_REVIEW,
            LegalDocument::STATUS_VALIDATED,
            LegalDocument::STATUS_PUBLISHED,
        ];
        $allowedStatuts = ['vigueur', 'abroge', 'projet'];

        if ($request->action === 'set_curation_status' && ! in_array($request->value, $allowedCurationStatuses, true)) {
            return $this->error(null, 'Valeur de statut de curation invalide.', 422);
        }

        if ($request->action === 'set_statut' && ! in_array($request->value, $allowedStatuts, true)) {
            return $this->error(null, 'Valeur de statut de validité invalide.', 422);
        }

        $column = $request->action === 'set_curation_status' ? 'curation_status' : 'statut';
        $isPublishing = $request->action === 'set_curation_status'
            && $request->value === LegalDocument::STATUS_PUBLISHED;

        $updated = DB::transaction(function () use ($request, $column, $isPublishing) {
            $query = LegalDocument::whereIn('id', $request->ids);

            if ($isPublishing) {
                // Mêmes gardes que la curation unitaire : un document sans aucun
                // article, ou conservant des anomalies de curation non résolues,
                // ne doit pas atteindre le catalogue publié.
                $query->whereHas('articles')
                    ->whereDoesntHave('curationFlags', function ($flagQuery) {
                        $flagQuery->where('resolved', false);
                    });
            }

            return $query->update([$column => $request->value, 'updated_at' => now()]);
        });

        $skipped = $isPublishing ? count($request->ids) - $updated : 0;
        $message = "{$updated} document(s) mis à jour avec succès.";
        if ($skipped > 0) {
            $message .= " {$skipped} document(s) non publié(s) : aucun article ou anomalies de curation non résolues.";
        }

        return $this->success(
            ['updated_count' => $updated, 'skipped_count' => $skipped],
            $message
        );
    }
}
