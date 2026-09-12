<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Détail nominatif des 2 jalons d'activation sans autre source de vérité
 * serveur — mibeko-dashboard#137. En ajout seul (`UPDATED_AT = null`, même
 * doctrine que `AiUsageLog`) ; `reference_id` est toujours un identifiant
 * opaque, jamais un texte de requête ou de réponse.
 */
class ProductActivationEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_SEARCH_USEFUL = 'search_useful';

    public const TYPE_SOURCE_OPENED_AFTER_ANSWER = 'source_opened_after_answer';

    /**
     * @var list<string>
     */
    public const EVENT_TYPES = [self::TYPE_SEARCH_USEFUL, self::TYPE_SOURCE_OPENED_AFTER_ANSWER];

    public const SURFACE_WEB = 'web';

    public const SURFACE_MOBILE = 'mobile';

    /**
     * @var list<string>
     */
    public const SURFACES = [self::SURFACE_WEB, self::SURFACE_MOBILE];

    public const REFERENCE_ARTICLE = 'article';

    public const REFERENCE_AI_USAGE_LOG = 'ai_usage_log';

    /**
     * Mapping type d'événement → type de référence. Seule source de vérité
     * de ce mapping — consommée à la fois par le contrôleur (pour écrire
     * `reference_type`) et par `ProductEventStoreRequest` (pour choisir la
     * bonne vérification serveur), jamais dupliquée.
     *
     * @var array<string, string>
     */
    private const REFERENCE_TYPE_BY_EVENT_TYPE = [
        self::TYPE_SEARCH_USEFUL => self::REFERENCE_ARTICLE,
        self::TYPE_SOURCE_OPENED_AFTER_ANSWER => self::REFERENCE_AI_USAGE_LOG,
    ];

    protected $fillable = [
        'user_id', 'event_type', 'surface', 'usage_context', 'onboarding_journey_version',
        'reference_type', 'reference_id', 'duration_ms', 'client_event_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'onboarding_journey_version' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public static function referenceTypeFor(string $eventType): string
    {
        return self::REFERENCE_TYPE_BY_EVENT_TYPE[$eventType]
            ?? throw new \InvalidArgumentException("Type d'événement inconnu : {$eventType}.");
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
