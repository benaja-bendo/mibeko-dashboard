<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Parcours d'onboarding versionné — mibeko-dashboard#136.
 *
 * Une ligne = une version immuable une fois `published` (jamais de `UPDATE`
 * du contenu, cf. `Article`/`ArticleVersion`) ; `is_active` distingue LA
 * version couramment servie pour une `key` parmi toutes celles publiées.
 */
class OnboardingJourney extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Catalogue fermé de TYPES d'étapes — constantes de classe, pas d'enum
     * PHP natif (ce dépôt n'en a jamais utilisé, cf. LegalDocument/MobileProfile).
     */
    public const TYPE_WELCOME = 'welcome';

    public const TYPE_SINGLE_CHOICE = 'single_choice';

    public const TYPE_MULTI_CHOICE = 'multi_choice';

    public const TYPE_OPTIONAL_FIELD = 'optional_field';

    public const TYPE_GUIDED_ACTION = 'guided_action';

    public const TYPE_CHECKLIST = 'checklist';

    /**
     * @var list<string>
     */
    public const STEP_TYPES = [
        self::TYPE_WELCOME, self::TYPE_SINGLE_CHOICE, self::TYPE_MULTI_CHOICE,
        self::TYPE_OPTIONAL_FIELD, self::TYPE_GUIDED_ACTION, self::TYPE_CHECKLIST,
    ];

    /** Ensemble FERMÉ de cibles de binding — jamais étendu par une chaîne libre côté contrôleur. */
    public const BINDING_USAGE_CONTEXT = 'profile.usage_context';

    public const BINDING_JOB_TITLE = 'profile.job_title';

    public const BINDING_COMPANY = 'profile.company';

    public const BINDING_PHONE = 'profile.phone';

    public const BINDING_INTERESTS = 'profile.interests';

    /**
     * @var list<string>
     */
    public const BINDINGS = [
        self::BINDING_USAGE_CONTEXT, self::BINDING_JOB_TITLE, self::BINDING_COMPANY,
        self::BINDING_PHONE, self::BINDING_INTERESTS,
    ];

    /**
     * Bindings dont `value` ne doit JAMAIS être persistée dans
     * `onboarding_step_progress` — la valeur vit uniquement dans
     * `mobile_profiles`, jamais dupliquée dans un événement de progression.
     *
     * @var list<string>
     */
    public const SENSITIVE_BINDINGS = [self::BINDING_PHONE];

    /**
     * @var list<string>
     */
    public const SCOPES = ['common', 'web', 'mobile'];

    /** Plateformes qu'un client peut déclarer — sous-ensemble de SCOPES sans "common". */
    public const PLATFORMS = ['web', 'mobile'];

    /**
     * @var list<string>
     */
    public const CONDITION_OPERATORS = ['equals', 'not_equals', 'in'];

    protected $fillable = ['key', 'version', 'status', 'is_active', 'definition', 'published_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'definition' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(OnboardingEnrollment::class, 'journey_id');
    }

    /**
     * Étape par clé stable, ou null si absente de cette version.
     *
     * @return array<string, mixed>|null
     */
    public function step(string $stepKey): ?array
    {
        return collect($this->definition)->firstWhere('key', $stepKey);
    }

    /**
     * Publie une nouvelle version pour `key` : désactive l'ancienne active
     * (s'il y en a une) puis crée+active la nouvelle, dans une transaction —
     * l'ordre importe (désactiver avant d'activer) à cause de l'index
     * unique partiel `onboarding_journeys_one_active_per_key`, qui refuse
     * deux lignes actives simultanées pour une même clé.
     *
     * Idempotent PAR CONTENU : si la version active porte déjà exactement
     * cette définition, ne crée rien et renvoie l'existante — c'est ce qui
     * rend `OnboardingJourneySeeder` rejouable sans dupliquer les versions.
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public static function publish(string $key, array $steps): self
    {
        return DB::transaction(function () use ($key, $steps) {
            $active = self::query()->where('key', $key)->where('is_active', true)->lockForUpdate()->first();

            if ($active !== null && self::hashDefinition($active->definition) === self::hashDefinition($steps)) {
                return $active;
            }

            if ($active !== null) {
                $active->update(['is_active' => false]);
            }

            $nextVersion = (int) (self::query()->where('key', $key)->max('version') ?? 0) + 1;

            return self::create([
                'key' => $key,
                'version' => $nextVersion,
                'status' => self::STATUS_PUBLISHED,
                'is_active' => true,
                'definition' => $steps,
                'published_at' => now(),
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private static function hashDefinition(array $steps): string
    {
        // Postgres jsonb NE préserve PAS l'ordre des clés d'un objet (il les
        // réordonne à l'écriture) — comparer un `json_encode` brut d'une
        // définition fraîchement construite en PHP à une définition relue
        // depuis `jsonb` donnerait donc des hash différents pour un contenu
        // strictement identique. On trie récursivement les clés des tableaux
        // associatifs (jamais l'ordre d'une liste, qui porte l'ordre des
        // étapes) avant de hacher, pour rendre la comparaison stable des deux
        // côtés du round-trip.
        return md5(json_encode(self::canonicalize($steps)));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $mapped = array_map(self::canonicalize(...), $value);

        if (array_is_list($value)) {
            return $mapped;
        }

        ksort($mapped);

        return $mapped;
    }
}
