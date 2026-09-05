<?php

namespace App\Ai;

use App\Models\AiQuotaTierSetting;
use App\Models\User;

/**
 * Palier de quota IA d'un utilisateur (mibeko-dashboard#62/#63).
 *
 * Seul point qui décide « admin / user_pro / standard » pour le quota de fond
 * de l'assistant : le limiteur de débit (`AppServiceProvider::boot()`) et le
 * résolveur d'entitlements (`EntitlementsResolver`) doivent retomber sur le
 * même palier et la même limite, sinon le quota annoncé au client diverge de
 * celui réellement appliqué. Ne couvre que le plafond de fond (jour/mois) —
 * le plafond par minute est un confort d'usage anti-rafale, pas une donnée
 * d'entitlement.
 *
 * mibeko-dashboard#95 : la LIMITE (jamais la portée jour/mois, qui reste
 * fixée par le rôle ci-dessous) peut être remplacée par, dans l'ordre de
 * priorité : un override posé sur le compte (vente manuelle, admin) > un
 * réglage de palier posé en base (admin) > `config('ai.quotas')` (le défaut,
 * qui ne disparaît jamais).
 */
class AiUserQuotaTier
{
    /**
     * Rôles qui donnent un quota IA élevé — nommés ici une seule fois
     * (mibeko-dashboard#85) pour qu'`EntitlementsResolver::resolvePlan()`
     * les réutilise au lieu de les redéviner dans une seconde liste tenue
     * à la main : c'est exactement cette duplication silencieuse qui avait
     * laissé `user_pro` reconnu ici mais ignoré là-bas.
     *
     * @var list<string>
     */
    public const ELEVATED_QUOTA_ROLES = ['admin', 'user_pro'];

    /**
     * Palier de l'utilisateur — seul endroit qui traduit un rôle Spatie en
     * palier de quota (mibeko-dashboard#95 : réutilisé par l'admin des
     * paliers, qui n'a donc pas à redéviner cette correspondance).
     */
    public static function tierFor(User $user): string
    {
        return match (true) {
            $user->hasRole('admin') => 'admin',
            $user->hasRole('user_pro') => 'user_pro',
            default => 'standard',
        };
    }

    /**
     * Portée (jour/mois) et clé `config/ai.php` d'un palier — FIXES, jamais
     * lues depuis la base : c'est ce qui empêche `ai_quota_tier_settings`
     * (réglable sans redéploiement) de réactiver par la bande la bascule
     * journalière du palier gratuit, décidée le 04/09/2026 mais explicitement
     * conditionnée à un préalable non construit (voir `docs/decisions.md`).
     *
     * @return array{scope: 'day'|'month', configKey: string}
     */
    public static function tierDefinition(string $tier): array
    {
        return match ($tier) {
            'admin' => ['scope' => 'day', 'configKey' => 'ai.quotas.admin.per_day'],
            'user_pro' => ['scope' => 'day', 'configKey' => 'ai.quotas.user_pro.per_day'],
            'standard' => ['scope' => 'month', 'configKey' => 'ai.quotas.standard.per_month'],
            default => throw new \InvalidArgumentException("Palier de quota IA inconnu : {$tier}"),
        };
    }

    /**
     * @return array{scope: 'day'|'month', limit: int}
     */
    public static function resolve(User $user): array
    {
        $tier = self::tierFor($user);
        ['scope' => $scope, 'configKey' => $configKey] = self::tierDefinition($tier);

        $override = $user->settings?->ai_quota_override_limit;
        if ($override !== null) {
            return ['scope' => $scope, 'limit' => $override];
        }

        $tierSetting = AiQuotaTierSetting::query()->where('tier', $tier)->value('limit');

        return ['scope' => $scope, 'limit' => $tierSetting !== null ? (int) $tierSetting : (int) config($configKey)];
    }

    /**
     * Clé de cache EXACTE utilisée par `ThrottleRequests` pour ce palier
     * (`md5($limiterName.$limit->key)`, `shouldHashKeys` vaut `true` par
     * défaut) — pour LIRE l'état courant du compteur, jamais pour l'appliquer.
     */
    public static function cacheKey(User $user, string $scope): string
    {
        return md5('ai_assistant'.$scope.':'.$user->id);
    }
}
