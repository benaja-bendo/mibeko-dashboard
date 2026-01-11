<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ArticleVersion extends Model implements Auditable
{
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable;

    protected $touches = ['article'];

    protected $auditExclude = [
        'search_tsv',
        'embedding',
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'article_id',
        'validity_period',
        'contenu_texte',
        'embedding_context',
        'embedding',
        'modifie_par_document_id',
        'validation_status',
        'is_verified',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => \Pgvector\Laravel\Vector::class,
            'is_verified' => 'boolean',
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

    /**
     * Helper to create a validity_period daterange string for PostgreSQL.
     * Format: [start_date, end_date) - inclusive start, exclusive end
     */
    public static function makeValidityPeriod(string $startDate, ?string $endDate = null): string
    {
        if ($endDate === null || $endDate === 'infinity') {
            return "[{$startDate},)";
        }

        return "[{$startDate}, {$endDate})";
    }
}
