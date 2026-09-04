<?php

namespace App\Services;

use App\Ai\AiUserQuotaTier;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Point unique de résolution du droit d'usage (mibeko-dashboard#63).
 *
 * Un rôle Spatie dit qui est la personne dans l'organisation (staff ou non),
 * jamais ce qu'elle a payé — confondre les deux est précisément pourquoi
 * `hasRole('premium')` était mort (#62) et pourquoi les permissions du
 * seeder ne sont vérifiées nulle part (#64). Le plan se résout donc depuis
 * l'abonnement Cashier, complété par les rôles staff (admin/editor) qui
 * héritent des mêmes droits sans abonnement. `mobile_user` n'entre dans
 * aucun calcul ici : c'est le rôle par défaut de toute auto-inscription
 * (web comprise), pas un palier — le traiter comme tel a été l'erreur
 * d'origine.
 *
 * Web et mobile doivent consommer cette charge utile à l'identique : aucun
 * client ne re-déduit la règle.
 */
class EntitlementsResolver
{
    /**
     * @return array{plan: 'libre'|'pro', features: array<string, bool>, quotas: array<string, mixed>, credits: null}
     */
    public function resolve(User $user): array
    {
        $plan = $this->resolvePlan($user);

        return [
            'plan' => $plan,
            'features' => [
                // Gratuit pour tous les paliers : la loi est gratuite, seul
                // l'outil de travail se paie (positionnement mobile-app-kmp).
                // La différenciation passe par le quota, pas par ce booléen.
                'assistant' => true,
                'library' => true,
                'export' => $plan === 'pro',
            ],
            'quotas' => [
                'assistant' => $this->assistantQuota($user),
            ],
            // Aucun système de crédits/solde n'existe encore dans le produit
            // (cf. mibeko-dashboard#76, arbitrage freemium toujours ouvert) —
            // le champ existe pour que le contrat de charge utile n'ait pas
            // à changer le jour où il apparaît.
            'credits' => null,
        ];
    }

    private function resolvePlan(User $user): string
    {
        if ($user->hasAnyRole(['admin', 'editor'])) {
            return 'pro';
        }

        return $user->subscribed('default') ? 'pro' : 'libre';
    }

    /**
     * @return array{used: int, limit: int, resets_at: ?string}
     */
    private function assistantQuota(User $user): array
    {
        ['scope' => $scope, 'limit' => $limit] = AiUserQuotaTier::resolve($user);
        $key = AiUserQuotaTier::cacheKey($user, $scope);

        $used = RateLimiter::attempts($key);
        $availableIn = RateLimiter::availableIn($key);

        return [
            'used' => $used,
            'limit' => $limit,
            'resets_at' => $used > 0 && $availableIn > 0
                ? now()->addSeconds($availableIn)->toIso8601String()
                : null,
        ];
    }
}
