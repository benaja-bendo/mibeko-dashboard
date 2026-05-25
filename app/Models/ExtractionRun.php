<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtractionRun extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'source',
        'status',
        'started_at',
        'finished_at',
        'source_media_file_id',
        'markdown_media_file_id',
        'json_media_file_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    public function sourceMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'source_media_file_id');
    }

    public function markdownMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'markdown_media_file_id');
    }

    public function jsonMediaFile(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'json_media_file_id');
    }
}
