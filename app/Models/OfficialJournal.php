<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OfficialJournal extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'title',
        'publication_date',
        'file_path',
        'transcription_status',
        'is_published',
    ];

    const STATUS_PENDING = 'pending';

    const STATUS_IN_PROGRESS = 'in_progress';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'publication_date' => 'date',
            'is_published' => 'boolean',
        ];
    }

    /**
     * Récupère les documents juridiques associés à ce journal officiel.
     */
    public function legalDocuments(): HasMany
    {
        return $this->hasMany(LegalDocument::class, 'official_journal_id');
    }
}
