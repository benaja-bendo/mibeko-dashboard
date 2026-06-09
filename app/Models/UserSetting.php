<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Préférences applicatives et consentements RGPD d'un utilisateur (1 ligne / user).
 *
 * Implémente Auditable : toute modification (notamment des consentements) est
 * tracée dans la table `audits` pour répondre aux exigences de conformité.
 */
class UserSetting extends Model implements Auditable
{
    use HasUuids;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'user_id',
        'locale',
        'timezone',
        'date_format',
        'notification_preferences',
        'marketing_consent',
        'marketing_consent_at',
        'analytics_consent',
        'analytics_consent_at',
        'billing_info',
    ];

    protected function casts(): array
    {
        return [
            'notification_preferences' => 'array',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'analytics_consent' => 'boolean',
            'analytics_consent_at' => 'datetime',
            'billing_info' => 'array',
        ];
    }

    /**
     * Types de notification supportés par la plateforme (clés stables côté API/front).
     *
     * @var list<string>
     */
    public const NOTIFICATION_TYPES = [
        'extraction_update', // Mise à jour d'une extraction de document
        'new_document',      // Nouveau document juridique publié
        'share',             // Partage d'un dossier / document
        'legal_alert',       // Alerte légale (échéances, nouveautés réglementaires)
        'system',            // Messages système / sécurité
    ];

    /**
     * Canaux de diffusion supportés.
     *
     * @var list<string>
     */
    public const NOTIFICATION_CHANNELS = ['email', 'push', 'in_app'];

    /**
     * Fréquences de regroupement supportées pour les notifications email.
     *
     * @var list<string>
     */
    public const NOTIFICATION_FREQUENCIES = ['instant', 'daily', 'weekly'];

    /**
     * Préférences de notification par défaut.
     *
     * Tout activé par défaut sur les canaux email + in-app ; push désactivé tant
     * qu'aucun appareil n'est enregistré. Les messages système restent toujours
     * actifs (sécurité) côté logique métier.
     *
     * @return array<string, mixed>
     */
    public static function defaultNotificationPreferences(): array
    {
        $channels = [];

        foreach (self::NOTIFICATION_TYPES as $type) {
            $channels[$type] = [
                'email' => true,
                'push' => false,
                'in_app' => true,
            ];
        }

        return array_merge($channels, ['_frequency' => 'instant']);
    }

    /**
     * Valeurs par défaut utilisées lors de la création implicite d'une ligne settings.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'locale' => 'fr',
            'timezone' => 'Africa/Kinshasa',
            'date_format' => 'd/m/Y',
            'notification_preferences' => self::defaultNotificationPreferences(),
            'marketing_consent' => false,
            'analytics_consent' => false,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
