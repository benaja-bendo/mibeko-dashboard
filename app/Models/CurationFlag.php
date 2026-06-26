<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurationFlag extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'curation_flags';

    const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'article_id',
        'node_id',
        'source',
        'type_probleme',
        'severity',
        'description',
        'suggestion',
        'anchor',
        'confidence',
        'run_id',
        'resolved',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'suggestion' => 'array',
        'anchor' => 'array',
        'confidence' => 'float',
    ];

    /** Origines possibles d'un signalement. Les flags `human` ne sont jamais purgés. */
    const SOURCE_HEURISTIC = 'heuristic';

    const SOURCE_STRUCTURAL = 'structural';

    const SOURCE_LLM = 'llm';

    const SOURCE_HUMAN = 'human';

    /** Sévérités : seul `blocking` empêche la publication. */
    const SEVERITY_BLOCKING = 'blocking';

    const SEVERITY_WARNING = 'warning';

    const SEVERITY_INFO = 'info';

    /**
     * Get the document that was flagged.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    /**
     * Get the article that was flagged.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    /**
     * Get the structure node (division) that was flagged, if any.
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(StructureNode::class, 'node_id');
    }

    /**
     * Get the admin who resolved the flag, if any.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
