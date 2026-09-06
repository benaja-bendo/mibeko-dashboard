<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une citation d'article par une décision de justice — mibeko-python#19.
 *
 * `cited_article_id` reste `null` quand la référence désigne un texte hors
 * périmètre du corpus Mibeko (droit national d'un autre État membre OHADA,
 * Code civil...) : `reference_brute` porte alors seule la citation.
 */
class JurisprudenceCitation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'decision_id',
        'cited_article_id',
        'reference_brute',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'decision_id');
    }

    public function citedArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'cited_article_id');
    }
}
