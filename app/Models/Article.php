<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $touches = ['document'];

    protected $fillable = [
        'document_id',
        'parent_node_id',
        'numero_article',
        'ordre_affichage',
        'validation_status',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class);
    }

    public function parentNode(): BelongsTo
    {
        return $this->belongsTo(StructureNode::class, 'parent_node_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class);
    }

    public function latestVersion()
    {
        return $this->hasOne(ArticleVersion::class)->orderByDesc('created_at');
    }

    public function activeVersion()
    {
        return $this->hasOne(ArticleVersion::class)->whereRaw('upper_inf(validity_period)');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Build a breadcrumb string for an article.
     */
    public function getBreadcrumbAttribute(): string
    {
        $parts = [];

        if ($this->document?->type) {
            $parts[] = $this->document->type->nom;
        }

        if ($this->document) {
            // Truncate long titles
            $title = $this->document->titre_officiel;
            if (strlen($title) > 40) {
                $title = mb_substr($title, 0, 40).'...';
            }
            $parts[] = $title;
        }

        if ($this->parentNode) {
            $parts[] = $this->parentNode->titre ?? $this->parentNode->numero;
        }

        return implode(' > ', $parts);
    }
}
