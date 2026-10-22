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
                'empreinte_source' => $this->empreinteSource($article, $contenu),
                'empreintes_heritees' => $this->empreintesHeritees($article, $contenu),
                'anchor' => $this->anchor($article),
            ];
        }

        return $candidats;
    }

    /**
     * Ce dont dépend l'anomalie : par défaut le contenu de l'article. Un
     * détecteur qui juge autre chose (le numéro) surcharge, sinon une
     * exception résolue survivrait à un changement de la chose jugée.
     */
    protected function empreinteSource(Article $article, string $contenu): string
    {
        return $contenu;
    }

    /**
     * Anciennes sources d'empreinte encore honorées pour une exception déjà
     * résolue (voir `JeuDeDetecteurs::poserSignalement()`). Vide par défaut.
     *
     * @return array<int, string>
     */
    protected function empreintesHeritees(Article $article, string $contenu): array
    {
        return [];
    }

    abstract protected function estAnomalie(string $contenu, Article $article): bool;

    abstract protected function description(Article $article, string $contenu): string;

    protected function anchor(Article $article): ?array
    {
        $page = $article->activeVersion?->source_locator['page'] ?? null;

        return $page !== null ? ['page' => $page] : null;
    }
}
