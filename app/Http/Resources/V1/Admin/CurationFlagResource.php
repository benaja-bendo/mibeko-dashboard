<?php

namespace App\Http\Resources\V1\Admin;

use App\Models\CurationFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurationFlagResource extends JsonResource
{
    /**
     * Représentation admin d'un signalement, enrichie de sa cible (document
     * ou article) et de la traçabilité de résolution.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CurationFlag $this */
        return [
            'id' => $this->id,
            'source' => $this->source,
            'type_probleme' => $this->type_probleme,
            'severity' => $this->severity,
            'description' => $this->description,
            'suggestion' => $this->suggestion,
            'confidence' => $this->confidence,
            'resolved' => (bool) $this->resolved,
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'target' => $this->buildTarget(),
        ];
    }

    /**
     * Décrit la cible du signalement et fournit l'id de document permettant
     * d'ouvrir le viewer éditeur, qu'on ait flaggé un document ou un article.
     *
     * @return array<string, mixed>
     */
    private function buildTarget(): array
    {
        /** @var CurationFlag $this */
        if ($this->article_id && $this->relationLoaded('article') && $this->article) {
            return [
                'kind' => 'article',
                'document_id' => $this->article->document_id,
                'document_title' => $this->article->relationLoaded('document') ? $this->article->document?->titre_officiel : null,
                'article_id' => $this->article->id,
                'article_number' => $this->article->numero_article,
            ];
        }

        return [
            'kind' => 'document',
            'document_id' => $this->document_id,
            'document_title' => $this->whenLoaded('document', fn () => $this->document?->titre_officiel),
            'article_id' => null,
            'article_number' => null,
        ];
    }
}
