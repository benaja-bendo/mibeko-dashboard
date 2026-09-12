<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Inscription d'un compte à UNE VERSION PRÉCISE d'un parcours — épinglée à
 * `journey_id` pour toujours (mibeko-dashboard#136). Une v2 publiée après
 * coup ne déplace jamais une inscription déjà créée.
 */
class OnboardingEnrollment extends Model
{
    use HasUuids;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_POSTPONED = 'postponed';

    public const STATUS_COMPLETED = 'completed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_POSTPONED, self::STATUS_COMPLETED,
    ];

    /**
     * Transitions autorisées "en avant" — jamais completed → in_progress ici
     * (réservé à l'action explicite /replay, cf. OnboardingController::replay).
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS_AVANT = [
        // "postponed" est atteignable depuis not_started : reporter un guide
        // jamais commencé (dismiss immédiat, sans avoir vu une étape) est
        // une action légitime, pas une anomalie.
        self::STATUS_NOT_STARTED => [self::STATUS_IN_PROGRESS, self::STATUS_POSTPONED],
        self::STATUS_IN_PROGRESS => [self::STATUS_POSTPONED, self::STATUS_COMPLETED],
        self::STATUS_POSTPONED => [self::STATUS_IN_PROGRESS],
    ];

    /**
     * Seule transition "en arrière" : le replay explicite.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS_ARRIERE = [
        self::STATUS_COMPLETED => [self::STATUS_IN_PROGRESS],
    ];

    protected $fillable = [
        'user_id', 'journey_id', 'journey_key', 'status', 'started_at', 'last_started_at',
        'completed_at', 'postponed_at', 'last_activity_at', 'replay_count', 'last_client_mutation_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'postponed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OnboardingEnrollment $enrollment) {
            if ($enrollment->exists && $enrollment->isDirty('status')) {
                static::guardStatusTransition($enrollment);
            }
        });
    }

    /**
     * Garde de transition — même forme que
     * `LegalDocument::guardCurationStatusTransition()`.
     */
    protected static function guardStatusTransition(OnboardingEnrollment $enrollment): void
    {
        $from = $enrollment->getOriginal('status');
        $to = $enrollment->status;

        if ($from === null || $from === $to) {
            return;
        }

        $autorisee = in_array($to, self::TRANSITIONS_AVANT[$from] ?? [], true)
            || in_array($to, self::TRANSITIONS_ARRIERE[$from] ?? [], true);

        if (! $autorisee) {
            throw ValidationException::withMessages([
                'status' => ["Transition d'inscription « {$from} » → « {$to} » non autorisée."],
            ]);
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(OnboardingJourney::class, 'journey_id');
    }

    public function stepsProgress(): HasMany
    {
        return $this->hasMany(OnboardingStepProgress::class, 'enrollment_id');
    }
}
