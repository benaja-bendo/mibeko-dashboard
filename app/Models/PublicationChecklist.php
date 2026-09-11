<?php

namespace App\Models;

use App\Services\Curation\PublicationGuardrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preuve de validation (dashboard#119) : un enregistrement immuable par
 * passage du garde-fou de publication ({@see PublicationGuardrail}),
 * réussi ou refusé. Réalise l'étape 6 « Certificat » de
 * `docs/pipeline/protocole-validation.md` — jamais mis à jour après coup,
 * seulement créé.
 */
class PublicationChecklist extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'actor_id',
        'target_status',
        'outcome',
        'criteria',
        'document_snapshot_updated_at',
    ];

    protected $casts = [
        'criteria' => 'array',
        'document_snapshot_updated_at' => 'datetime',
    ];

    /** Critères satisfaits : la transition a été acceptée sans dérogation. */
    const OUTCOME_PASSED = 'passed';

    /** Refusé par le garde-fou : au moins un critère obligatoire manque. */
    const OUTCOME_BLOCKED = 'blocked';

    /** Critères non satisfaits mais `force=true` assumé par l'éditeur. */
    const OUTCOME_FORCED = 'forced';

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'document_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
