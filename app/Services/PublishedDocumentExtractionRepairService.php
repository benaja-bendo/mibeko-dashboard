<?php

namespace App\Services;

use App\Ai\CorpusVersion;
use App\Jobs\GenerateDocumentExportPdfJob;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use App\Models\MediaFile;
use App\Models\StructureNode;
use App\Observers\LegalDocumentObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Remplace atomiquement l'extraction d'un document déjà publié sans effacer
 * son historique, y compris pendant un retrait public provisoire.
 *
 * Les articles conservés gardent leur UUID et leur version active : une erreur
 * d'extraction n'est pas une nouvelle version du droit. Une trace compacte de
 * l'opération (motif, source, empreintes et compteurs) est auditée sur le
 * document ; auditer séparément plusieurs milliers de versions dupliquerait le
 * corpus dans `audits`. Articles et divisions retirés sont seulement soft-deleted.
 * Un snapshot produit par ce service est directement réutilisable comme cible
 * de retour arrière.
 */
class PublishedDocumentExtractionRepairService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(LegalDocument $document): array
    {
        $target = $this->currentTarget($document);

        return [
            'expected_fingerprint' => $this->fingerprint($target),
            'semantic_fingerprint' => $this->semanticFingerprint($target),
            'target' => $target,
            'counts' => [
                'nodes' => count($target['nodes']),
                'articles' => count($target['articles']),
                'characters' => array_sum(array_map(
                    fn (array $article): int => mb_strlen($article['content']),
                    $target['articles'],
                )),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function dryRun(
        LegalDocument $document,
        array $target,
        string $expectedFingerprint,
    ): array {
        $this->assertAlreadyPublished($document);
        $normalizedTarget = $this->normalizeAndValidateTarget($document, $target);
        $this->assertSourcePdf($document, $normalizedTarget['source_pdf']['sha256']);

        $current = $this->snapshot($document);
        $targetSemantic = $this->semanticFingerprint($normalizedTarget);
        $alreadyApplied = hash_equals($targetSemantic, $current['semantic_fingerprint']);

        if (! $alreadyApplied && ! hash_equals($expectedFingerprint, $current['expected_fingerprint'])) {
            throw new ConflictHttpException(
                'L’extraction publiée a changé depuis la mesure préparatoire. Arrêter et remesurer avant toute écriture.'
            );
        }

        return [
            'dry_run' => true,
            'already_applied' => $alreadyApplied,
            'before_fingerprint' => $current['expected_fingerprint'],
            'target_semantic_fingerprint' => $targetSemantic,
            'plan' => $this->buildPlan($current['target'], $normalizedTarget),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function execute(
        LegalDocument $document,
        array $target,
        string $expectedFingerprint,
        string $motif,
        string $userId,
    ): array {
        $normalizedTarget = $this->normalizeAndValidateTarget($document, $target);

        $result = DB::transaction(function () use (
            $document,
            $normalizedTarget,
            $expectedFingerprint,
            $motif,
            $userId,
        ): array {
            $lockedDocument = LegalDocument::query()->lockForUpdate()->findOrFail($document->id);
            $this->assertAlreadyPublished($lockedDocument);
            $this->assertSourcePdf($lockedDocument, $normalizedTarget['source_pdf']['sha256']);

            $current = $this->snapshot($lockedDocument);
            $targetSemantic = $this->semanticFingerprint($normalizedTarget);
            if (hash_equals($targetSemantic, $current['semantic_fingerprint'])) {
                return [
                    'executed' => false,
                    'already_applied' => true,
                    'curation_status' => $lockedDocument->curation_status,
                    'before_fingerprint' => $current['expected_fingerprint'],
                    'after_fingerprint' => $current['expected_fingerprint'],
                    'semantic_fingerprint' => $current['semantic_fingerprint'],
                    'plan' => $this->buildPlan($current['target'], $normalizedTarget),
                ];
            }

            if (! hash_equals($expectedFingerprint, $current['expected_fingerprint'])) {
                throw new ConflictHttpException(
                    'L’extraction publiée a changé depuis la mesure préparatoire. Transaction annulée.'
                );
            }

            $plan = $this->buildPlan($current['target'], $normalizedTarget);
            $operationId = (string) Str::uuid();
            $previousSkipWarmup = LegalDocumentObserver::$shouldSkipExportPdfWarmup;

            try {
                LegalDocumentObserver::$shouldSkipExportPdfWarmup = true;

                // Le remplacement passe par le query builder dans la transaction :
                // hydrater simultanément plusieurs milliers de modèles Eloquent,
                // leurs versions et leurs audits dépasse la limite mémoire de
                // production. L'unique audit métier est porté par le document.
                $actual = $this->applyTarget($lockedDocument, $normalizedTarget);

                $after = $this->snapshot($lockedDocument);
                if (! hash_equals($targetSemantic, $after['semantic_fingerprint'])) {
                    throw new \LogicException('La vérification sémantique après réparation a échoué.');
                }

                $metadata = $lockedDocument->metadata ?? [];
                $history = is_array($metadata['extraction_repairs'] ?? null)
                    ? $metadata['extraction_repairs']
                    : [];
                $history[] = [
                    'operation_id' => $operationId,
                    'executed_at' => now()->toIso8601String(),
                    'executed_by' => $userId,
                    'motif' => $motif,
                    'source_pdf_sha256' => $normalizedTarget['source_pdf']['sha256'],
                    'before_fingerprint' => $current['expected_fingerprint'],
                    'after_fingerprint' => $after['expected_fingerprint'],
                    'counts' => $actual,
                ];
                $metadata['extraction_repairs'] = $history;
                $lockedDocument->update(['metadata' => $metadata]);
            } finally {
                LegalDocumentObserver::$shouldSkipExportPdfWarmup = $previousSkipWarmup;
            }

            Log::warning('Extraction d’un document publié remplacée atomiquement.', [
                'operation_id' => $operationId,
                'document_id' => $lockedDocument->id,
                'user_id' => $userId,
                'motif' => $motif,
                'source_pdf_sha256' => $normalizedTarget['source_pdf']['sha256'],
                'before_fingerprint' => $current['expected_fingerprint'],
                'after_fingerprint' => $after['expected_fingerprint'],
                'counts' => $actual,
            ]);

            return [
                'executed' => true,
                'already_applied' => false,
                'operation_id' => $operationId,
                'curation_status' => $lockedDocument->curation_status,
                'before_fingerprint' => $current['expected_fingerprint'],
                'after_fingerprint' => $after['expected_fingerprint'],
                'semantic_fingerprint' => $after['semantic_fingerprint'],
                'plan' => $plan,
                'actual' => $actual,
            ];
        }, 3);

        if ($result['executed'] && $result['curation_status'] === LegalDocument::STATUS_PUBLISHED) {
            CorpusVersion::bump();
            GenerateDocumentExportPdfJob::dispatch($document->id);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function normalizeAndValidateTarget(LegalDocument $document, array $target): array
    {
        if (($target['document_id'] ?? null) !== $document->id) {
            throw ValidationException::withMessages([
                'target.document_id' => ['La cible ne correspond pas au document de la route.'],
            ]);
        }

        $nodes = collect($target['nodes'] ?? [])->sortBy('order')->values()->all();
        $articles = collect($target['articles'] ?? [])->sortBy('order')->values()->all();
        $nodeKeys = array_column($nodes, 'key');

        if (count($nodeKeys) !== count(array_unique($nodeKeys))) {
            throw ValidationException::withMessages([
                'target.nodes' => ['Chaque clé de division doit être unique.'],
            ]);
        }

        $numbers = array_column($articles, 'number');
        if (count($numbers) !== count(array_unique($numbers))) {
            throw ValidationException::withMessages([
                'target.articles' => ['Chaque numéro d’article doit être unique.'],
            ]);
        }

        $allOrders = array_merge(array_column($nodes, 'order'), array_column($articles, 'order'));
        if (count($allOrders) !== count(array_unique($allOrders))) {
            throw ValidationException::withMessages([
                'target' => ['Les divisions et articles doivent partager un ordre global unique.'],
            ]);
        }

        $knownNodes = [];
        foreach ($nodes as $index => &$node) {
            $parent = $node['parent'] ?? null;
            if ($parent !== null && ! isset($knownNodes[$parent])) {
                throw ValidationException::withMessages([
                    "target.nodes.{$index}.parent" => ['Le parent doit exister et précéder son enfant.'],
                ]);
            }
            $knownNodes[$node['key']] = true;
            $node['number'] = $node['number'] ?? null;
            $node['title'] = $node['title'] ?? null;
        }
        unset($node);

        foreach ($articles as $index => &$article) {
            $parent = $article['parent'] ?? null;
            if ($parent !== null && ! isset($knownNodes[$parent])) {
                throw ValidationException::withMessages([
                    "target.articles.{$index}.parent" => ['La division parente n’existe pas dans la cible.'],
                ]);
            }

            if (array_key_exists('source_locator', $article)) {
                $article['source_locator'] = $article['source_locator'] ?? [];
            } elseif (isset($article['page'])) {
                $article['source_locator'] = ['page' => (int) $article['page']];
                if (isset($article['page_end']) && (int) $article['page_end'] !== (int) $article['page']) {
                    $article['source_locator']['page_end'] = (int) $article['page_end'];
                }
            } else {
                throw ValidationException::withMessages([
                    "target.articles.{$index}.source_locator" => ['Un repère de source ou une page est obligatoire.'],
                ]);
            }
        }
        unset($article);

        $sha256 = strtolower((string) data_get($target, 'source_pdf.sha256'));
        if (! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw ValidationException::withMessages([
                'target.source_pdf.sha256' => ['L’empreinte SHA-256 de la source est invalide.'],
            ]);
        }

        return [
            'schema_version' => 1,
            'document_id' => $document->id,
            'source_pdf' => [
                ...($target['source_pdf'] ?? []),
                'sha256' => $sha256,
            ],
            'nodes' => $nodes,
            'articles' => $articles,
        ];
    }

    private function assertAlreadyPublished(LegalDocument $document): void
    {
        if (! $document->hasEverBeenPublished()) {
            throw new ConflictHttpException(
                'Ce canal est réservé à la réparation d’un document publié ou temporairement retiré du public.'
            );
        }
    }

    private function assertSourcePdf(LegalDocument $document, string $sha256): void
    {
        $matches = MediaFile::query()
            ->where('document_id', $document->id)
            ->where('file_category', 'SOURCE_PDF')
            ->whereRaw('lower(checksum_sha256) = ?', [strtolower($sha256)])
            ->exists();

        if (! $matches) {
            throw new ConflictHttpException(
                'L’empreinte de la cible ne correspond à aucun PDF source rattaché au document.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function currentTarget(LegalDocument $document): array
    {
        $sourcePdf = MediaFile::query()
            ->where('document_id', $document->id)
            ->where('file_category', 'SOURCE_PDF')
            ->orderByDesc('created_at')
            ->first();

        $nodeRows = DB::table('structure_nodes')
            ->where('document_id', $document->id)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $nodeIdByPathSegment = $nodeRows->flatMap(function (object $node): array {
            $segment = str_replace('-', '_', $node->id);

            return [$segment => $node->id, 'n_'.$segment => $node->id];
        });
        $nodes = $nodeRows
            ->map(fn (object $node): array => [
                'key' => $node->id,
                'id' => $node->id,
                'parent' => $this->parentIdFromPath($node->tree_path, $nodeIdByPathSegment->all()),
                'type' => $node->type_unite,
                'number' => $node->numero,
                'title' => $node->titre,
                'order' => (int) ($node->sort_order ?? 0),
            ])
            ->all();

        $articles = DB::table('articles as a')
            ->join('article_versions as av', function ($join): void {
                $join->on('av.article_id', '=', 'a.id')
                    ->whereRaw('upper_inf(av.validity_period)');
            })
            ->where('a.document_id', $document->id)
            ->whereNull('a.deleted_at')
            ->orderBy('a.ordre_affichage')
            ->orderBy('a.id')
            ->select([
                'a.id',
                'a.numero_article',
                'a.parent_node_id',
                'a.ordre_affichage',
                'av.contenu_texte',
                'av.source_locator',
            ])
            ->get()
            ->map(fn (object $article): array => [
                'id' => $article->id,
                'number' => $article->numero_article,
                'parent' => $article->parent_node_id,
                'order' => (int) ($article->ordre_affichage ?? 0),
                'content' => $article->contenu_texte ?? '',
                'source_locator' => is_string($article->source_locator)
                    ? (json_decode($article->source_locator, true) ?: [])
                    : ($article->source_locator ?? []),
            ])
            ->all();

        return [
            'schema_version' => 1,
            'document_id' => $document->id,
            'source_pdf' => [
                'filename' => $sourcePdf?->original_filename,
                'sha256' => strtolower((string) $sourcePdf?->checksum_sha256),
                'size' => $sourcePdf?->file_size,
            ],
            'nodes' => $nodes,
            'articles' => $articles,
        ];
    }

    /**
     * @param  array<string, string>  $nodeIdByPathSegment
     */
    private function parentIdFromPath(string $treePath, array $nodeIdByPathSegment): ?string
    {
        $parts = explode('.', $treePath);

        if (count($parts) <= 1) {
            return null;
        }

        return $nodeIdByPathSegment[$parts[count($parts) - 2]] ?? null;
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function fingerprint(array $target): string
    {
        return $this->hashCanonical([
            'document_id' => $target['document_id'],
            'source_pdf_sha256' => strtolower((string) data_get($target, 'source_pdf.sha256')),
            'nodes' => $target['nodes'],
            'articles' => $target['articles'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function semanticFingerprint(array $target): string
    {
        $orderByNodeKey = collect($target['nodes'])->mapWithKeys(
            fn (array $node): array => [$node['key'] => (int) $node['order']],
        );

        $nodes = collect($target['nodes'])
            ->sortBy('order')
            ->map(fn (array $node): array => [
                'parent_order' => ($node['parent'] ?? null) === null
                    ? null
                    : $orderByNodeKey[$node['parent']],
                'type' => $node['type'],
                'number' => $node['number'] ?? null,
                'title' => $node['title'] ?? null,
                'order' => (int) $node['order'],
            ])
            ->values()
            ->all();

        $articles = collect($target['articles'])
            ->sortBy('order')
            ->map(fn (array $article): array => [
                'number' => $article['number'],
                'parent_order' => ($article['parent'] ?? null) === null
                    ? null
                    : $orderByNodeKey[$article['parent']],
                'order' => (int) $article['order'],
                'content' => $article['content'],
                'source_locator' => $article['source_locator'],
            ])
            ->values()
            ->all();

        return $this->hashCanonical([
            'document_id' => $target['document_id'],
            'source_pdf_sha256' => strtolower((string) data_get($target, 'source_pdf.sha256')),
            'nodes' => $nodes,
            'articles' => $articles,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function hashCanonical(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $target
     * @return array<string, int>
     */
    private function buildPlan(array $current, array $target): array
    {
        $currentByNumber = collect($current['articles'])->keyBy('number');
        $targetByNumber = collect($target['articles'])->keyBy('number');
        $contentChanges = 0;
        $locatorChanges = 0;

        foreach ($targetByNumber as $number => $article) {
            $existing = $currentByNumber->get($number);
            if (! $existing) {
                continue;
            }
            $contentChanges += $existing['content'] !== $article['content'] ? 1 : 0;
            $locatorChanges += $this->canonicalize($existing['source_locator']) !== $this->canonicalize($article['source_locator']) ? 1 : 0;
        }

        return [
            'nodes_soft_deleted' => count($current['nodes']),
            'nodes_target' => count($target['nodes']),
            'articles_soft_deleted' => $currentByNumber->keys()->diff($targetByNumber->keys())->count(),
            'articles_added_or_restored' => $targetByNumber->keys()->diff($currentByNumber->keys())->count(),
            'articles_reparented_and_reordered' => count($target['articles']),
            'article_contents_updated' => $contentChanges,
            'article_locators_updated' => $locatorChanges,
            'target_articles' => count($target['articles']),
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, int>
     */
    private function applyTarget(LegalDocument $document, array $target): array
    {
        $liveNodeIdsBefore = DB::table('structure_nodes')
            ->where('document_id', $document->id)
            ->whereNull('deleted_at')
            ->pluck('id');
        $nodeIdByKey = [];
        $nodeTreePathById = [];
        $targetNodeIds = [];
        $nodesCreated = 0;
        $nodesRestored = 0;

        foreach ($target['nodes'] as $nodeData) {
            $requestedId = $nodeData['id'] ?? null;
            $node = $requestedId
                ? StructureNode::withTrashed()->find($requestedId)
                : null;

            if ($node && $node->document_id !== $document->id) {
                throw ValidationException::withMessages([
                    'target.nodes' => ["La division {$requestedId} appartient à un autre document."],
                ]);
            }

            if (! $node) {
                $node = new StructureNode;
                $node->id = $requestedId ?: (string) Str::uuid();
                $node->document_id = $document->id;
                $nodesCreated++;
            } elseif ($node->trashed()) {
                $node->deleted_at = null;
                $nodesRestored++;
            }

            $parentId = ($nodeData['parent'] ?? null) === null
                ? null
                : $nodeIdByKey[$nodeData['parent']];
            $slugId = str_replace('-', '_', $node->id);
            $treePath = $parentId === null
                ? $slugId
                : $nodeTreePathById[$parentId].'.'.$slugId;

            $node->forceFill([
                'type_unite' => $nodeData['type'],
                'numero' => $nodeData['number'] ?? null,
                'titre' => $nodeData['title'] ?? null,
                'tree_path' => $treePath,
                'sort_order' => (int) $nodeData['order'],
                'validation_status' => $node->validation_status ?: 'pending',
            ])->save(['touch' => false]);

            $nodeIdByKey[$nodeData['key']] = $node->id;
            $nodeTreePathById[$node->id] = $treePath;
            $targetNodeIds[] = $node->id;
        }

        $sourcePdfId = MediaFile::query()
            ->where('document_id', $document->id)
            ->where('file_category', 'SOURCE_PDF')
            ->whereRaw('lower(checksum_sha256) = ?', [$target['source_pdf']['sha256']])
            ->value('id');
        $articleRows = DB::table('articles')
            ->where('document_id', $document->id)
            ->select(['id', 'document_id', 'numero_article', 'deleted_at'])
            ->get()
            ->map(fn (object $row): array => (array) $row);
        $articleById = $articleRows->keyBy('id');
        $liveByNumber = $articleRows->whereNull('deleted_at')->keyBy('numero_article');
        $trashedByNumber = $articleRows->whereNotNull('deleted_at')->groupBy('numero_article');
        $activeVersions = DB::table('article_versions as av')
            ->join('articles as a', 'a.id', '=', 'av.article_id')
            ->where('a.document_id', $document->id)
            ->whereRaw('upper_inf(av.validity_period)')
            ->select(['av.id', 'av.article_id', 'av.contenu_texte', 'av.source_locator'])
            ->get()
            ->mapWithKeys(function (object $row): array {
                $sourceLocator = is_string($row->source_locator)
                    ? (json_decode($row->source_locator, true) ?: [])
                    : ($row->source_locator ?? []);

                return [$row->article_id => [
                    'id' => $row->id,
                    'content' => $row->contenu_texte ?? '',
                    'source_locator' => $sourceLocator,
                ]];
            });
        $usedArticleIds = [];
        $articlesCreated = 0;
        $articlesRestored = 0;
        $contentsUpdated = 0;
        $locatorsUpdated = 0;
        $embeddingsInvalidated = 0;

        foreach ($target['articles'] as $articleData) {
            $requestedId = $articleData['id'] ?? null;
            $articleRow = $requestedId
                ? $articleById->get($requestedId)
                : $liveByNumber->get($articleData['number']);
            $articleRow ??= $trashedByNumber->get($articleData['number'])?->first();

            if ($requestedId && ! $articleRow && DB::table('articles')->where('id', $requestedId)->exists()) {
                throw ValidationException::withMessages([
                    'target.articles' => ["L’article {$requestedId} appartient à un autre document."],
                ]);
            }

            if ($articleRow && $articleRow['document_id'] !== $document->id) {
                throw ValidationException::withMessages([
                    'target.articles' => ["L’article {$requestedId} appartient à un autre document."],
                ]);
            }

            if (! $articleRow) {
                $articleId = $requestedId ?: (string) Str::uuid();
                $versionId = (string) Str::uuid();
                DB::table('articles')->insert([
                    'id' => $articleId,
                    'document_id' => $document->id,
                    'parent_node_id' => null,
                    'numero_article' => $articleData['number'],
                    'ordre_affichage' => (int) $articleData['order'],
                    'validation_status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]);
                DB::table('article_versions')->insert([
                    'id' => $versionId,
                    'article_id' => $articleId,
                    'contenu_texte' => $articleData['content'],
                    'source_locator' => json_encode($articleData['source_locator'], JSON_THROW_ON_ERROR),
                    'source_media_file_id' => $sourcePdfId,
                    'validity_period' => ArticleVersion::makeValidityPeriod(now()->toDateString()),
                    'validation_status' => 'pending',
                    'is_verified' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $articleRow = [
                    'id' => $articleId,
                    'document_id' => $document->id,
                    'numero_article' => $articleData['number'],
                    'deleted_at' => null,
                ];
                $activeVersions[$articleId] = [
                    'id' => $versionId,
                    'content' => $articleData['content'],
                    'source_locator' => $articleData['source_locator'],
                ];
                $articlesCreated++;
            } elseif ($articleRow['deleted_at'] !== null) {
                DB::table('articles')->where('id', $articleRow['id'])->update([
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);
                $articlesRestored++;
            }

            $articleId = $articleRow['id'];
            if (isset($usedArticleIds[$articleId])) {
                throw ValidationException::withMessages([
                    'target.articles' => ['Deux entrées cibles résolvent vers le même article.'],
                ]);
            }
            $usedArticleIds[$articleId] = true;

            $activeVersion = $activeVersions->get($articleId);
            if (! $activeVersion) {
                throw ValidationException::withMessages([
                    'target.articles' => ["L’article {$articleData['number']} n’a aucune version active."],
                ]);
            }

            $contentChanged = $activeVersion['content'] !== $articleData['content'];
            $locatorChanged = $this->canonicalize($activeVersion['source_locator'] ?? [])
                !== $this->canonicalize($articleData['source_locator']);
            if ($contentChanged || $locatorChanged) {
                $updates = ['updated_at' => now()];
                if ($contentChanged) {
                    $updates['contenu_texte'] = $articleData['content'];
                    $updates['embedding'] = null;
                    $updates['embedding_context'] = null;
                    $contentsUpdated++;
                    $embeddingsInvalidated++;
                }
                if ($locatorChanged) {
                    $updates['source_locator'] = json_encode($articleData['source_locator'], JSON_THROW_ON_ERROR);
                    $locatorsUpdated++;
                }
                DB::table('article_versions')->where('id', $activeVersion['id'])->update($updates);
            }

            DB::table('articles')->where('id', $articleId)->update([
                'parent_node_id' => ($articleData['parent'] ?? null) === null
                    ? null
                    : $nodeIdByKey[$articleData['parent']],
                'ordre_affichage' => (int) $articleData['order'],
                'updated_at' => now(),
            ]);
        }

        $articleIdsSoftDeleted = $articleRows
            ->whereNull('deleted_at')
            ->reject(fn (array $article): bool => isset($usedArticleIds[$article['id']]))
            ->pluck('id');
        if ($articleIdsSoftDeleted->isNotEmpty()) {
            DB::table('articles')->whereIn('id', $articleIdsSoftDeleted)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $nodeIdsSoftDeleted = $liveNodeIdsBefore->diff($targetNodeIds);
        if ($nodeIdsSoftDeleted->isNotEmpty()) {
            DB::table('structure_nodes')->whereIn('id', $nodeIdsSoftDeleted)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'nodes_created' => $nodesCreated,
            'nodes_restored' => $nodesRestored,
            'nodes_soft_deleted' => $nodeIdsSoftDeleted->count(),
            'articles_created' => $articlesCreated,
            'articles_restored' => $articlesRestored,
            'articles_soft_deleted' => $articleIdsSoftDeleted->count(),
            'article_contents_updated' => $contentsUpdated,
            'article_locators_updated' => $locatorsUpdated,
            'embeddings_invalidated' => $embeddingsInvalidated,
        ];
    }
}
