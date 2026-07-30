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
        'theme',
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
    /** Nouveau texte juridique publié — c'est le type porté par la veille légale. */
    public const TYPE_NEW_DOCUMENT = 'new_document';

    public const NOTIFICATION_TYPES = [
        'extraction_update', // Mise à jour d'une extraction de document
        self::TYPE_NEW_DOCUMENT, // Nouveau document juridique publié
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
                // La veille légale (publication d'un nouveau texte) est la
                // raison d'être des notifications de l'app : un push désactivé
                // par défaut la rendait invisible pour tout compte connecté,
                // alors même que l'utilisateur a accordé la permission système.
                // Les autres types restent en opt-in explicite.
                'push' => $type === self::TYPE_NEW_DOCUMENT,
                'in_app' => true,
            ];
        }

        return array_merge($channels, ['_frequency' => 'instant']);
    }

    /**
     * Valeur par défaut d'un couple (type, canal) — sert de repli quand un
     * utilisateur n'a jamais enregistré de préférences, ou quand la matrice
     * stockée est antérieure à l'ajout d'un type/canal.
     */
    public static function notificationDefaultFor(string $type, string $channel): bool
    {
        return (bool) (self::defaultNotificationPreferences()[$type][$channel] ?? false);
    }

    /**
     * L'utilisateur accepte-t-il ce type de notification sur ce canal ?
     *
     * Premier consommateur : la veille légale (`LegalWatchNotifier`), qui gate
     * l'écriture d'une ligne `notifications` sur le canal `in_app` et l'envoi
     * push sur le canal `push`.
     */
    public function allowsNotification(string $type, string $channel): bool
    {
        $preferences = $this->notification_preferences;

        if (! is_array($preferences) || ! array_key_exists($type, $preferences)) {
            return self::notificationDefaultFor($type, $channel);
        }

        $matrix = $preferences[$type];

        if (! is_array($matrix) || ! array_key_exists($channel, $matrix)) {
            return self::notificationDefaultFor($type, $channel);
        }

        return (bool) $matrix[$channel];
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
            'theme' => 'lex-gold',
            'timezone' => 'Africa/Brazzaville',
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
