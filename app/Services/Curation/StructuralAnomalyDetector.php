<?php

namespace App\Services\Curation;

use App\Models\Article;
use App\Models\CurationFlag;
use App\Models\LegalDocument;
use App\Models\StructureNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Couche de détection STRUCTURELLE des anomalies d'extraction : des règles
 * déterministes, à haute confiance, qui inspectent l'ARBRE (divisions) et la
 * position des feuilles — sans appel IA, donc gratuites et exécutables à chaque
 * ingestion.
 *
 * Elle écrit dans `curation_flags` (source = `structural`) qui alimente l'arbre
 * de l'éditeur (badge ✗) et le garde-fou de publication. Idempotente : un
 * re-run purge ses propres signalements non résolus avant de recalculer, sans
 * jamais toucher aux signalements humains ni aux autres couches.
 */
class StructuralAnomalyDetector
{
    /** Feuilles spéciales hors-contrôle « article orphelin » (légitimement à la racine). */
    private const SPECIAL_LEAF_FORMATS = ['preamble', 'signature', 'table'];

    /**
     * Analyse un document et (re)crée ses signalements structurels.
     *
     * @return array<int, CurationFlag> Les signalements créés (utile aux tests).
     */
    public function detect(LegalDocument $document): array
    {
        $runId = (string) Str::uuid();

        $nodes = StructureNode::where('document_id', $document->id)
            ->withCount('articles')
            ->get();

        $articles = Article::where('document_id', $document->id)
            ->with('activeVersion')
            ->get();

        $candidates = array_merge(
            $this->emptyArticles($articles),
            $this->untitledDivisions($nodes),
            $this->emptyDivisions($nodes),
            $this->orphanArticles($articles, $nodes),
        );

        return DB::transaction(function () use ($document, $candidates, $runId): array {
            // Idempotence : on efface nos propres signalements non résolus, jamais
            // ceux d'un humain ni des autres couches (heuristique, LLM).
            CurationFlag::where('document_id', $document->id)
                ->where('source', CurationFlag::SOURCE_STRUCTURAL)
                ->where('resolved', false)
                ->delete();

            $created = [];
            foreach ($candidates as $candidate) {
                $created[] = CurationFlag::create(array_merge($candidate, [
                    'document_id' => $document->id,
                    'source' => CurationFlag::SOURCE_STRUCTURAL,
                    'run_id' => $runId,
                    'resolved' => false,
                ]));
            }

            return $created;
        });
    }

    /**
     * Articles dont le contenu courant est vide/blanc → perte de texte probable.
     * Couvre aussi un préambule/signature vidé. Sévérité bloquante.
     *
     * @param  Collection<int, Article>  $articles
     * @return array<int, array<string, mixed>>
     */
    private function emptyArticles($articles): array
    {
        $flags = [];
        foreach ($articles as $article) {
            $content = $article->activeVersion?->contenu_texte ?? '';
            if (trim($content) !== '') {
                continue;
            }

            $flags[] = [
                'article_id' => $article->id,
                'type_probleme' => 'article_vide',
                'severity' => CurationFlag::SEVERITY_BLOCKING,
                'description' => "L'article {$article->numero_article} n'a aucun contenu : texte probablement perdu à l'extraction.",
                'anchor' => $this->articleAnchor($article),
            ];
        }

        return $flags;
    }

    /**
     * Divisions sans identité (ni numéro ni titre) : artefact de parsing fréquent.
     *
     * @param  Collection<int, StructureNode>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function untitledDivisions($nodes): array
    {
        $flags = [];
        foreach ($nodes as $node) {
            if (trim((string) $node->titre) !== '' || trim((string) $node->numero) !== '') {
                continue;
            }

            $flags[] = [
                'node_id' => $node->id,
                'type_probleme' => 'division_sans_titre',
                'severity' => CurationFlag::SEVERITY_WARNING,
                'description' => "Division ({$node->type_unite}) sans numéro ni titre : artefact d'extraction probable.",
            ];
        }

        return $flags;
    }

    /**
     * Divisions « feuilles » (sans sous-division) ne contenant aucun article.
     *
     * @param  Collection<int, StructureNode>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function emptyDivisions($nodes): array
    {
        // Ensemble des chemins qui sont parents d'au moins une autre division.
        $parentPaths = [];
        foreach ($nodes as $node) {
            $path = (string) $node->tree_path;
            $cut = strrpos($path, '.');
            if ($cut !== false) {
                $parentPaths[substr($path, 0, $cut)] = true;
            }
        }

        $flags = [];
        foreach ($nodes as $node) {
            $hasChildNodes = isset($parentPaths[(string) $node->tree_path]);
            if ($hasChildNodes || ($node->articles_count ?? 0) > 0) {
                continue;
            }

            $label = trim(($node->numero ?? '').' '.($node->titre ?? '')) ?: $node->type_unite;
            $flags[] = [
                'node_id' => $node->id,
                'type_probleme' => 'division_vide',
                'severity' => CurationFlag::SEVERITY_WARNING,
                'description' => "Division « {$label} » vide : aucun article ni sous-division rattaché.",
            ];
        }

        return $flags;
    }

    /**
     * Articles « flottants » (parent_node_id NULL) alors que le document EST
     * structuré — hors feuilles spéciales (préambule/signature/tableau) qui sont
     * légitimement à la racine.
     *
     * @param  Collection<int, Article>  $articles
     * @param  Collection<int, StructureNode>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function orphanArticles($articles, $nodes): array
    {
        if ($nodes->isEmpty()) {
            return [];
        }

        $flags = [];
        foreach ($articles as $article) {
            if ($article->parent_node_id !== null || $this->isSpecialLeaf($article)) {
                continue;
            }

            $flags[] = [
                'article_id' => $article->id,
                'type_probleme' => 'article_hors_structure',
                'severity' => CurationFlag::SEVERITY_WARNING,
                'description' => "L'article {$article->numero_article} n'est rattaché à aucune division alors que le document est structuré.",
                'anchor' => $this->articleAnchor($article),
            ];
        }

        return $flags;
    }

    /**
     * Vrai pour une feuille spéciale (préambule, signature, tableau), repérée par
     * le `content_format` du locator (posé à l'ingestion) ou, à défaut, le numéro.
     */
    private function isSpecialLeaf(Article $article): bool
    {
        $format = $article->activeVersion?->source_locator['content_format'] ?? null;
        if (in_array($format, self::SPECIAL_LEAF_FORMATS, true)) {
            return true;
        }

        return Str::startsWith($article->numero_article, ['PREAMBULE', 'SIGNATURE', 'TABLEAU']);
    }

    /**
     * Ancrage (page PDF) d'un article pour le surlignage côté validation.
     *
     * @return array<string, mixed>|null
     */
    private function articleAnchor(Article $article): ?array
    {
        $page = $article->activeVersion?->source_locator['page'] ?? null;

        return $page !== null ? ['page' => $page] : null;
    }
}
