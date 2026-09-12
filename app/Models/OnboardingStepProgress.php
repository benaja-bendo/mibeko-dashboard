<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Progression par étape, identifiée par une clé STABLE (`step_key`) —
 * mibeko-dashboard#136. `viewed_at`/`skipped_at`/`completed_at` sont trois
 * faits indépendants, jamais un statut unique. `completed_at` est immuable
 * une fois posé (cf. OnboardingStepWriter::apply()) : ce modèle ne fait que
 * l'exposer via `isTerminal()`, la garde vit dans le service qui écrit.
 */
class OnboardingStepProgress extends Model
{
    use HasUuids;

    public const ACTION_VIEW = 'view';

    public const ACTION_ANSWER = 'answer';

    public const ACTION_SKIP = 'skip';

    /**
     * @var list<string>
     */
    public const ACTIONS = [self::ACTION_VIEW, self::ACTION_ANSWER, self::ACTION_SKIP];

    protected $fillable = [
        'enrollment_id', 'step_key', 'viewed_at', 'skipped_at', 'completed_at',
        'value', 'last_client_mutation_id', 'client_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'skipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'value' => 'array',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(OnboardingEnrollment::class, 'enrollment_id');
    }

    public function isTerminal(): bool
    {
        return $this->completed_at !== null;
    }
}
