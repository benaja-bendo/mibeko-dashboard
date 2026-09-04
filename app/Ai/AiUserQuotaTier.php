<?php

namespace App\Ai;

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
 */
class AiUserQuotaTier
{
    /**
     * @return array{scope: 'day'|'month', limit: int}
     */
    public static function resolve(User $user): array
    {
        return match (true) {
            $user->hasRole('admin') => ['scope' => 'day', 'limit' => (int) config('ai.quotas.admin.per_day')],
            $user->hasRole('user_pro') => ['scope' => 'day', 'limit' => (int) config('ai.quotas.user_pro.per_day')],
            default => ['scope' => 'month', 'limit' => (int) config('ai.quotas.standard.per_month')],
        };
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
