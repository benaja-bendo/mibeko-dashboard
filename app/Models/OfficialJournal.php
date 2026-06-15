<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class OfficialJournal extends Model implements Auditable
{
    use AuditableTrait, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'title',
        'number',
        'publication_date',
        'file_path',
        'transcription_status',
        'is_published',
    ];

    /**
     * `file_path` est un détail technique de stockage : exclu de l'audit.
     *
     * @var array<int, string>
     */
    protected $auditExclude = ['file_path'];

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
