<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;
use Pgvector\Laravel\Vector;

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
        'source_run_id',
        'source_media_file_id',
        'source_locator',
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
            'embedding' => Vector::class,
            'is_verified' => 'boolean',
            'source_locator' => 'array',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(ExtractionRun::class, 'source_run_id');
    }

    public function sourceMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'source_media_file_id');
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
