<?php

namespace App\Jobs;

use App\Ai\AnomalyDetector;
use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Couche SÉMANTIQUE de détection d'anomalies (LLM), exécutée en file.
 *
 * Cible les feuilles SUSPECTES (longueur aberrante ou déjà signalées par une
 * autre couche) pour maîtriser le coût, les soumet par lots à {@see AnomalyDetector},
 * puis écrit des `curation_flags` (source = `llm`) qui alimentent l'arbre et le
 * garde-fou de publication. Idempotente (purge ses propres flags non résolus) et
 * à dégradation gracieuse (panne IA → aucun flag, aucune exception propagée).
 */
class DetectDocumentAnomalies implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Plafond de feuilles analysées par exécution (budget tokens). */
    private const MAX_ITEMS = 80;

    /** Taille d'un lot envoyé au modèle. */
    private const BATCH_SIZE = 15;

    /** Contenu plus court que ceci → fragment/troncature probable. */
    private const SHORT_CONTENT = 40;

    /** Contenu plus long que ceci → fusion d'articles probable. */
    private const LONG_CONTENT = 6000;

    public function __construct(public string $documentId) {}

    public function handle(AnomalyDetector $agent): void
    {
        $document = LegalDocument::find($this->documentId);
        if (! $document) {
            return;
        }

        $articles = Article::where('document_id', $document->id)
            ->with(['activeVersion', 'parentNode'])
            ->get();

        $suspects = $this->selectSuspects($document, $articles);
        if ($suspects->isEmpty()) {
            // Rien de suspect : on purge quand même nos anciens flags non résolus
            // (une correction antérieure a pu lever la suspicion).
            $this->purgePreviousLlmFlags($document->id);

            return;
        }

        $byRef = $suspects->keyBy('id');
        $anomalies = [];

        foreach ($suspects->chunk(self::BATCH_SIZE) as $batch) {
            $items = $batch->map(fn (Article $a) => [
                'ref' => $a->id,
                'breadcrumb' => $a->breadcrumb,
                'numero' => (string) $a->numero_article,
                'contenu' => (string) ($a->activeVersion?->contenu_texte ?? ''),
            ])->values()->all();

            foreach ($agent->analyze($items) as $anomaly) {
                $anomalies[] = $anomaly;
            }
        }

        DB::transaction(function () use ($document, $anomalies, $byRef): void {
            $this->purgePreviousLlmFlags($document->id);

            $runId = (string) Str::uuid();
            foreach ($anomalies as $anomaly) {
                $article = $byRef->get($anomaly['ref']);
                if (! $article) {
                    continue;
                }

                $page = $article->activeVersion?->source_locator['page'] ?? null;

                CurationFlag::create([
                    'document_id' => $document->id,
                    'article_id' => $article->id,
                    'source' => CurationFlag::SOURCE_LLM,
                    'type_probleme' => $anomaly['type_probleme'],
                    'severity' => $anomaly['severity'],
                    'description' => $anomaly['description'],
                    'suggestion' => $anomaly['suggestion'] !== null ? ['text' => $anomaly['suggestion']] : null,
                    'confidence' => $anomaly['confidence'],
                    'anchor' => $page !== null ? ['page' => $page] : null,
                    'run_id' => $runId,
                    'resolved' => false,
                ]);
            }
        });
    }

    /**
     * Sélectionne les feuilles à soumettre au LLM : longueurs aberrantes ∪ déjà
     * signalées par une autre couche ; à défaut, un échantillon de tête. Plafonné.
     *
     * @param  Collection<int, Article>  $articles
     * @return Collection<int, Article>
     */
    private function selectSuspects(LegalDocument $document, Collection $articles): Collection
    {
        $flaggedIds = CurationFlag::where('document_id', $document->id)
            ->where('resolved', false)
            ->whereNotNull('article_id')
            ->pluck('article_id')
            ->flip();

        $suspects = $articles->filter(function (Article $a) use ($flaggedIds) {
            $len = mb_strlen(trim((string) ($a->activeVersion?->contenu_texte ?? '')));

            return $len < self::SHORT_CONTENT
                || $len > self::LONG_CONTENT
                || $flaggedIds->has($a->id);
        });

        // Aucun suspect évident : contrôle ponctuel d'un échantillon de tête.
        if ($suspects->isEmpty() && $articles->isNotEmpty()) {
            $suspects = $articles->sortBy('ordre_affichage')->take(10);
        }

        return $suspects->take(self::MAX_ITEMS)->values();
    }

    private function purgePreviousLlmFlags(string $documentId): void
    {
        CurationFlag::where('document_id', $documentId)
            ->where('source', CurationFlag::SOURCE_LLM)
            ->where('resolved', false)
            ->delete();
    }
}
