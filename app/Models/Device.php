<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use HasFactory, HasUuids;

    /**
     * Préfixe des jetons factices produits par le simulateur iOS : aucun envoi
     * FCM ne peut aboutir dessus. Cf. `mibeko:purge-simulated-devices`.
     */
    public const SIMULATED_TOKEN_PREFIX = 'fcm_token_simulated_';

    protected $fillable = [
        'user_id',
        'device_id',
        'push_token',
        'platform',
        // Version de l'app installée, annoncée à l'enregistrement (v1.2+ only) :
        // elle décide de la FORME du push envoyé à cet appareil. `null` = version
        // inconnue = format hérité. Cf. `SendLegalWatchNotifications`.
        'app_version',
        'status',
        'last_registered_at',
    ];

    protected $casts = [
        'last_registered_at' => 'datetime',
    ];

    /**
     * Propriétaire de l'appareil — `null` pour un appareil enregistré en mode
     * invité (l'app mobile pousse son jeton avant toute connexion).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include active devices.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Appareils réellement joignables par FCM : actifs, porteurs d'un jeton et
     * hors jetons simulés du simulateur iOS (chaque envoi échouerait).
     */
    public function scopePushable(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('push_token')
            ->whereNot('push_token', 'like', self::simulatedTokenPattern());
    }

    /**
     * Motif LIKE des jetons simulés, `_` échappé — sans échappement il jouerait
     * son rôle de joker et élargirait silencieusement la sélection.
     */
    public static function simulatedTokenPattern(): string
    {
        return str_replace('_', '\_', self::SIMULATED_TOKEN_PREFIX).'%';
    }
}
