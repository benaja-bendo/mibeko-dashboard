<?php

namespace App\Http\Resources\V1;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Article $this */
        return [
            'id' => $this->id,
            'number' => $this->numero_article,
            'order' => $this->ordre_affichage,
            'content' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion->contenu_texte),
            'source_locator' => $this->whenLoaded('activeVersion', fn () => $this->activeVersion->source_locator),
            'document_id' => $this->document_id,
            'document_title' => $this->document?->titre_officiel,
            'document_type' => $this->document?->type?->code,
            'node_title' => $this->parentNode?->titre ?? '',
            'breadcrumb' => $this->breadcrumb,
            'validation_status' => $this->validation_status,
            'versions' => $this->whenLoaded('versions', function () {
                return $this->versions->map(fn ($v) => [
                    'id' => $v->id,
                    'date' => $v->created_at->format('Y-m-d'),
                    'created_at' => $v->created_at->toDateTimeString(),
                    'type' => 'modification',
                    'title' => 'Version du '.$v->created_at->format('d/m/Y'),
                    'author' => 'Système',
                    'contenu_texte' => $v->contenu_texte,
                    'validation_status' => $v->validation_status,
                    'pending' => $v->validation_status !== 'validated',
                ]);
            }),
        ];
    }
}
