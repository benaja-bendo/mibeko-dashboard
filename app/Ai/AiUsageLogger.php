<?php

namespace App\Ai;

use App\Models\AiUsageLog;
use App\Models\User;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Point d'écriture unique du journal d'usage IA — mibeko-dashboard#61.
 *
 * Centralise le calcul du coût estimé (grille `config('ai.pricing')`) pour
 * que les cinq points d'appel (chat en cache/flux/synchrone, explain,
 * synthesis, plus le refus par quota) ne dupliquent jamais cette logique.
 * N'écrit jamais rien d'autre que `ai_usage_logs` : ne pas lui faire porter
 * de logique métier au-delà de la journalisation.
 */
class AiUsageLogger
{
    public function success(
        ?User $user,
        string $route,
        string $provider,
        string $model,
        Usage $usage,
        ?string $conversationId = null,
    ): AiUsageLog {
        return $this->write($user, $route, AiUsageLog::STATUS_SUCCESS, $provider, $model, $usage->promptTokens, $usage->completionTokens, $conversationId);
    }

    /**
     * Réponse servie depuis le cache applicatif : aucun appel fournisseur,
     * donc aucun coût — mais la question a bien consommé le quota (comptée
     * par le RateLimiter avant d'atteindre le contrôleur), elle mérite une
     * ligne au même titre qu'un appel réel.
     */
    public function cached(?User $user, string $route, ?string $conversationId = null): AiUsageLog
    {
        return $this->write($user, $route, AiUsageLog::STATUS_CACHED, null, null, 0, 0, $conversationId);
    }

    /**
     * Appel à la route qui n'a jamais atteint le fournisseur (validation
     * métier en échec : article introuvable, recherche sans résultat…).
     */
    public function noContent(?User $user, string $route): AiUsageLog
    {
        return $this->write($user, $route, AiUsageLog::STATUS_NO_CONTENT, null, null, 0, 0);
    }

    /**
     * Requête refusée par le limiteur (429) — mibeko-dashboard#61 le demande
     * explicitement : « un 429 est une donnée ».
     */
    public function rateLimited(?User $user, string $route): AiUsageLog
    {
        return $this->write($user, $route, AiUsageLog::STATUS_RATE_LIMITED, null, null, 0, 0);
    }

    /**
     * Appel fournisseur qui a échoué après avoir démarré (erreur réseau,
     * fournisseur en panne…) — jetons connus si le fournisseur les a rendus
     * avant l'échec, sinon zéro.
     */
    public function error(?User $user, string $route, ?string $provider = null, ?string $model = null, ?string $conversationId = null): AiUsageLog
    {
        return $this->write($user, $route, AiUsageLog::STATUS_ERROR, $provider, $model, 0, 0, $conversationId);
    }

    private function write(
        ?User $user,
        string $route,
        string $status,
        ?string $provider,
        ?string $model,
        int $tokensInput,
        int $tokensOutput,
        ?string $conversationId = null,
    ): AiUsageLog {
        return AiUsageLog::create([
            'user_id' => $user?->id,
            'route' => $route,
            'status' => $status,
            'provider' => $provider,
            'model' => $model,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'cost_estimated_fcfa' => $this->estimateCost($provider, $model, $tokensInput, $tokensOutput),
            'conversation_id' => $conversationId,
        ]);
    }

    /**
     * `null` si le couple fournisseur/modèle n'a pas de tarif connu — jamais
     * un coût inventé (config('ai.pricing') documente pourquoi).
     */
    private function estimateCost(?string $provider, ?string $model, int $tokensInput, int $tokensOutput): ?float
    {
        if ($provider === null || $model === null) {
            return null;
        }

        $tarif = config("ai.pricing.{$provider}.{$model}");

        if (! is_array($tarif)) {
            return null;
        }

        $cout = ($tokensInput / 1_000_000) * $tarif['input_per_million']
            + ($tokensOutput / 1_000_000) * $tarif['output_per_million'];

        return round($cout, 4);
    }
}
