<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleVersion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'article_id',
        'valid_from',
        'valid_until',
        'contenu_texte',
        'modifie_par_document_id',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function modifiedByDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'modifie_par_document_id');
    }
}
