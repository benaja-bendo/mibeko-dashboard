<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRelation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'source_doc_id',
        'target_doc_id',
        'source_article_id',
        'target_article_id',
        'relation_type',
        'commentaire',
    ];

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'source_doc_id');
    }

    public function targetDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'target_doc_id');
    }

    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'source_article_id');
    }

    public function targetArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'target_article_id');
    }
}
