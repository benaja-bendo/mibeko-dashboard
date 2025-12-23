<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class ArticleVersion extends Model implements Auditable
{
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable;

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
        'modifie_par_document_id',
    ];

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
        $end = $endDate ?? 'infinity';

        return "[{$startDate}, {$end})";
    }
}
