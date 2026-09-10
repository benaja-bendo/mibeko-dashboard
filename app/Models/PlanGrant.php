<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Un abonnement Pro vendu à la main, borné dans le temps — mibeko-dashboard#100.
 *
 * Consommé en lecture par `EntitlementsResolver::resolvePlan()` et par
 * `App\Ai\AiUserQuotaTier::tierFor()` : les deux doivent reconnaître un
 * octroi actif de façon identique, exactement pour la raison qui a motivé
 * `AiUserQuotaTier::ELEVATED_QUOTA_ROLES` (mibeko-dashboard#85) — une
 * définition de « qui est Pro » dupliquée finit toujours par diverger.
 */
class PlanGrant extends Model implements Auditable
{
    use HasFactory, HasUuids, \OwenIt\Auditing\Auditable;

    public const PLAN_PRO = 'pro';

    protected $fillable = [
        'user_id',
        'plan',
        'starts_at',
        'ends_at',
        'amount_fcfa',
        'channel',
        'reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'revoked_at' => 'datetime',
        'amount_fcfa' => 'integer',
    ];

    /**
     * `starts_at` par défaut sur l'horloge PHP, jamais laissé au seul défaut
     * SQL (`useCurrent()`) de la migration — celui-ci reste un filet pour un
     * insert écrit hors Eloquent. La base de développement tourne dans
     * Docker : son horloge peut devancer celle du process PHP de quelques
     * dizaines de millisecondes, ce qui suffit à faire échouer par
     * intermittence `starts_at <= now()` (`activeFor()`) si les deux valeurs
     * ne viennent pas de la MÊME horloge — constaté en écriture des tests
     * de ce modèle (échec flaky reproduit et diagnostiqué le 06/09/2026).
     */
    protected static function booted(): void
    {
        static::creating(function (self $grant): void {
            $grant->starts_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Grand livre FCFA (encaissements, remboursements, corrections) — mibeko-dashboard#122. */
    public function movements(): HasMany
    {
        return $this->hasMany(PlanGrantMovement::class);
    }

    /** Données partageables avec le titulaire, sans les notes internes. */
    public function customerPayload(): array
    {
        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'status' => $this->status(),
            'amount_fcfa' => $this->amount_fcfa,
            'channel' => $this->channel,
            'reference' => $this->reference,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * `revoked` prime sur `ended`/`scheduled` : une coupure anticipée reste
     * visible même après la date de fin contractuelle d'origine, pour ne
     * pas se confondre avec une échéance normalement arrivée à son terme.
     */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->ends_at->isPast() || $this->ends_at->equalTo(now())
            ? 'ended'
            : ($this->starts_at->isFuture() ? 'scheduled' : 'active');
    }

    /**
     * Coupe l'accès immédiatement sans toucher `ends_at` : la période
     * contractuelle d'origine reste lisible sur le justificatif et dans
     * l'historique, seule `revoked_at` distingue une fin anticipée d'une
     * échéance normale (mibeko-dashboard#121).
     */
    public function revoke(): void
    {
        // `forceFill` à dessein : `revoked_at` est hors `$fillable` pour
        // qu'aucun payload admin de masse ne puisse le poser en douce, cette
        // méthode reste le seul point d'écriture légitime.
        $this->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Vrai si l'utilisateur a, à cet instant, un octroi de ce plan en cours
     * de validité. Requête live plutôt qu'un booléen mis en cache : un
     * octroi expiré cesse de compter au tick suivant, sans job ni redéploi.
     */
    public static function hasActive(User $user, string $plan = self::PLAN_PRO): bool
    {
        return static::activeFor($user, $plan)->exists();
    }

    /**
     * L'octroi actif le plus tardif (échéance la plus lointaine), pour
     * l'afficher à l'utilisateur ou à l'admin. `null` si aucun n'est en cours.
     */
    public static function latestActiveFor(User $user, string $plan = self::PLAN_PRO): ?self
    {
        return static::activeFor($user, $plan)->orderByDesc('ends_at')->first();
    }

    /**
     * @return Builder<self>
     */
    private static function activeFor(User $user, string $plan)
    {
        return static::query()
            ->where('user_id', $user->id)
            ->where('plan', $plan)
            ->active();
    }

    /**
     * Octrois en cours de validité à cet instant : bornes contractuelles ET
     * non révoqués. Seul point de vérité pour « actif » — consommé ici, dans
     * `BillingController`/`UserController` (admin) et par tout futur appelant,
     * pour éviter que la définition de « qui est Pro » ne diverge entre eux
     * (déjà arrivé une fois, cf. `AiUserQuotaTier::ELEVATED_QUOTA_ROLES`).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('starts_at', '<=', now())
            // Strict, pas `>=` : une révocation pose `revoked_at = now()`, et
            // le cast `datetime` tronque à la seconde à l'écriture comme à la
            // lecture — un `>=` laisserait l'octroi actif jusqu'à la seconde
            // suivante au lieu de s'éteindre immédiatement.
            ->where('ends_at', '>', now())
            ->whereNull('revoked_at');
    }
}
