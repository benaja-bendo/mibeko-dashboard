<?php

namespace App\Services\Curation\Detecteurs;

use App\Models\Article;
use App\Models\LegalDocument;

/**
 * Squelette commun aux détecteurs qui inspectent le contenu courant de
 * chaque article (la majorité du jeu v3) : un article par candidat, ancré
 * sur sa page source comme `StructuralAnomalyDetector::articleAnchor()`.
 */
abstract class DetecteurParArticle implements DetecteurContenu
{
    /**
     * @return array<int, array{article_id: string, description: string, anchor: array<string, mixed>|null}>
     */
    public function detecter(LegalDocument $document): array
    {
        $candidats = [];

        foreach ($document->articles()->with('activeVersion')->get() as $article) {
            $contenu = $article->activeVersion?->contenu_texte ?? '';
            if (! $this->estAnomalie($contenu, $article)) {
                continue;
            }

            $candidats[] = [
                'article_id' => $article->id,
                'description' => $this->description($article, $contenu),
                'empreinte_source' => $contenu,
                'anchor' => $this->anchor($article),
            ];
        }

        return $candidats;
    }

    abstract protected function estAnomalie(string $contenu, Article $article): bool;

    abstract protected function description(Article $article, string $contenu): string;

    protected function anchor(Article $article): ?array
    {
        $page = $article->activeVersion?->source_locator['page'] ?? null;

        return $page !== null ? ['page' => $page] : null;
    }
}
