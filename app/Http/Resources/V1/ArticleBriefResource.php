<?php

namespace App\Http\Resources\V1;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Simplified article resource for tree/structure endpoints.
 * Matches mobile's RemoteArticleBrief model.
 */
class ArticleBriefResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Article $this */
        // Une anomalie de curation non résolue prime : l'arbre affiche ✗ (error)
        // au bon endroit. Sinon, on retombe sur le statut de la version courante.
        $openFlags = (int) ($this->open_flags_count ?? 0);

        return [
            'id' => $this->id,
            'number' => $this->numero_article ?? '',
            'order' => $this->ordre_affichage ?? 0,
            'content' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->contenu_texte),
            'source_locator' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->source_locator),
            'anomaly_count' => $openFlags,
            'validation_status' => $openFlags > 0
                ? 'error'
                : $this->whenLoaded('activeVersion', fn () => $this->activeVersion?->validation_status ?? 'validated', 'validated'),
            'versions' => $this->whenLoaded('versions', function () {
                return $this->versions->map(fn ($v) => [
                    'id' => $v->id,
                    'date' => $v->created_at->format('Y-m-d'),
                    'created_at' => $v->created_at->toDateTimeString(),
                    'contenu_texte' => $v->contenu_texte,
                    'validation_status' => $v->validation_status,
                ]);
            }),
        ];
    }
}
