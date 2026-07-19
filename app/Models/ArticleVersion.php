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
        'reviewed_by',
        'reviewed_at',
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
            'reviewed_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Juriste ayant relu ce contenu (null tant qu'aucune relecture n'a eu lieu).
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Borne basse (date de début) de la période de validité enregistrée.
     *
     * Le daterange Postgres revient sous forme de chaîne « [2020-01-01,) » ; on
     * en extrait la date de début. Renvoie null si la borne est ouverte/absente.
     *
     * Cette date reflète la période de validité ENREGISTRÉE, qui peut avoir été
     * initialisée à la date d'ingestion faute de mieux (cf. commentaire de la
     * colonne). Elle est donc exposée telle quelle, sans être présentée comme une
     * date d'entrée en vigueur garantie : l'honnêteté du signal l'exige.
     */
    public function getValidityStartAttribute(): ?string
    {
        if (! is_string($this->validity_period) || $this->validity_period === '') {
            return null;
        }

        // Capture la première date ISO après le crochet ouvrant « [ » ou « ( ».
        if (preg_match('/^[\[(]\s*(\d{4}-\d{2}-\d{2})/', $this->validity_period, $matches)) {
            return $matches[1];
        }

        return null;
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
