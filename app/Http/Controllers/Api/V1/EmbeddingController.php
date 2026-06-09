<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\EmbedArticleChunkJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * @group Embeddings
 *
 * Génération vectorielle (RAG) des articles d'un document, à la demande.
 */
class EmbeddingController extends Controller
{
    /**
     * Nombre d'articles traités par lot (1 appel API batch par lot).
     */
    private const CHUNK_SIZE = 25;

    /**
     * Lance l'indexation des articles non encore vectorisés d'un document.
     */
    public function trigger(LegalDocument $document): JsonResponse
    {
        if ($this->activeBatchId($document) !== null) {
            return $this->success(
                ['pending_count' => $this->pendingVersionIds($document)->count(), 'in_progress' => true],
                'Une indexation est déjà en cours pour ce document.'
            );
        }

        $pendingIds = $this->pendingVersionIds($document);

        if ($pendingIds->isEmpty()) {
            return $this->success(
                ['pending_count' => 0, 'in_progress' => false],
                'Tous les articles ont déjà un embedding.'
            );
        }

        $jobs = $pendingIds
            ->chunk(self::CHUNK_SIZE)
            ->map(fn (Collection $chunk) => new EmbedArticleChunkJob($chunk->values()->all()))
            ->all();

        $batch = Bus::batch($jobs)
            ->name($this->batchName($document))
            ->allowFailures()
            ->dispatch();

        return $this->success([
            'batch_id' => $batch->id,
            'pending_count' => $pendingIds->count(),
            'total_chunks' => count($jobs),
            'in_progress' => true,
        ], "Indexation lancée pour {$pendingIds->count()} article(s).");
    }

    /**
     * Interrompt l'indexation en cours. Les embeddings déjà calculés sont conservés.
     */
    public function cancel(LegalDocument $document): JsonResponse
    {
        $batchId = $this->activeBatchId($document);

        if ($batchId === null) {
            return $this->success(['in_progress' => false], 'Aucune indexation en cours.');
        }

        Bus::findBatch($batchId)?->cancel();

        return $this->success(['in_progress' => false], 'Indexation interrompue. Les articles déjà indexés sont conservés.');
    }

    /**
     * IDs des versions actives, sans embedding et avec contenu, pour ce document.
     */
    private function pendingVersionIds(LegalDocument $document): Collection
    {
        return ArticleVersion::query()
            ->whereNull('embedding')
            ->whereNotNull('contenu_texte')
            ->whereRaw('upper_inf(validity_period)')
            ->whereIn('article_id', Article::where('document_id', $document->id)->select('id'))
            ->pluck('id');
    }

    /**
     * ID du lot d'indexation actif (non terminé, non annulé) pour ce document, le cas échéant.
     */
    private function activeBatchId(LegalDocument $document): ?string
    {
        return DB::table('job_batches')
            ->where('name', $this->batchName($document))
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->orderByDesc('created_at')
            ->value('id');
    }

    private function batchName(LegalDocument $document): string
    {
        return "embed-doc:{$document->id}";
    }
}
