<?php

namespace App\Ai;

use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
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
        ?string $id = null,
    ): AiUsageLog {
        return $this->write($user, $route, AiUsageLog::STATUS_SUCCESS, $provider, $model, $usage->promptTokens, $usage->completionTokens, $conversationId, $id);
    }

    /**
     * Réponse servie depuis le cache applicatif : aucun appel fournisseur,
     * donc aucun coût — mais la question a bien consommé le quota (comptée
     * par le RateLimiter avant d'atteindre le contrôleur), elle mérite une
     * ligne au même titre qu'un appel réel.
     */
    public function cached(?User $user, string $route, ?string $conversationId = null, ?string $id = null): AiUsageLog
    {
        return $this->write($user, $route, AiUsageLog::STATUS_CACHED, null, null, 0, 0, $conversationId, $id);
    }

    /**
     * Appel à la route qui n'a jamais atteint le fournisseur (validation
     * métier en échec : article introuvable, recherche sans résultat…).
     */
    public function noContent(?User $user, string $route, ?string $id = null): AiUsageLog
    {
        return $this->write($user, $route, AiUsageLog::STATUS_NO_CONTENT, null, null, 0, 0, id: $id);
    }

    /**
     * Requête refusée par le limiteur (429) — mibeko-dashboard#61 le demande
     * explicitement : « un 429 est une donnée ». Jamais liée à une écriture du
     * grand livre : un refus ne consomme ni quota ni crédit.
     */
    public function rateLimited(?User $user, string $route): AiUsageLog
    {
        return $this->write($user, $route, AiUsageLog::STATUS_RATE_LIMITED, null, null, 0, 0);
    }

    /**
     * Appel fournisseur qui a échoué après avoir démarré (erreur réseau,
     * fournisseur en panne…) — jetons connus si le fournisseur les a rendus
     * avant l'échec, sinon zéro.
     *
     * mibeko-dashboard#84 : `$exception`, quand fourni, pose la classe et un
     * message tronqué/nettoyé sur la ligne — de quoi root-causer le prochain
     * incident depuis la base, sans logs serveur ni accès au VPS. `provider`/
     * `model` restent `null` si l'appelant ne les connaît pas encore à
     * l'endroit du `catch` : jamais une valeur devinée.
     */
    public function error(
        ?User $user,
        string $route,
        ?string $provider = null,
        ?string $model = null,
        ?string $conversationId = null,
        ?string $id = null,
        ?\Throwable $exception = null,
    ): AiUsageLog {
        return $this->write(
            $user,
            $route,
            AiUsageLog::STATUS_ERROR,
            $provider,
            $model,
            0,
            0,
            $conversationId,
            $id,
            errorClass: $exception === null ? null : $exception::class,
            errorMessage: $exception ? $this->sanitizeErrorMessage($exception->getMessage()) : null,
        );
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
        ?string $id = null,
        ?string $errorClass = null,
        ?string $errorMessage = null,
    ): AiUsageLog {
        // mibeko-dashboard#83 : un id peut être imposé par l'appelant — posé
        // par le limiteur `ai_assistant` quand cette requête a consommé un
        // crédit, pour que l'écriture de consommation du grand livre
        // (CreditLedger) et cette ligne se référencent mutuellement. `new` +
        // affectation directe plutôt que `create()` : `id` n'a pas à devenir
        // fillable pour ce seul appelant interne. HasUuids ne génère un id
        // que si l'attribut est encore vide, donc l'affectation ici est
        // respectée telle quelle.
        $log = new AiUsageLog([
            'user_id' => $user?->id,
            'route' => $route,
            'status' => $status,
            'provider' => $provider,
            'model' => $model,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'cost_estimated_fcfa' => $this->estimateCost($provider, $model, $tokensInput, $tokensOutput),
            'conversation_id' => $conversationId,
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
        ]);

        if ($id !== null) {
            $log->id = $id;
        }

        $log->save();

        // mibeko-dashboard#99 : un échec qui n'a livré aucune réponse ne doit
        // pas coûter de quota — voir `refundQuota()`.
        if ($user !== null && $id === null && in_array($status, [AiUsageLog::STATUS_ERROR, AiUsageLog::STATUS_NO_CONTENT], true)) {
            $this->refundQuota($user);
        }

        return $log;
    }

    /**
     * Annule le hit du limiteur `ai_assistant` (`AppServiceProvider::boot()`)
     * quand une question n'a livré aucune réponse — mibeko-dashboard#99.
     *
     * `ThrottleRequests` compte une question dès qu'elle ENTRE dans
     * l'application (le hit a lieu avant le contrôleur, voir la classe
     * `Illuminate\Routing\Middleware\ThrottleRequests::handleRequest()`), pas
     * quand une réponse est réellement livrée. Un échec fournisseur (panne,
     * mauvaise configuration de failover…) consommait donc le quota d'un
     * utilisateur pour un incident dont il n'est pas responsable — confirmé
     * en production par l'incident `AI_ASSISTANT_FAILOVER` du 05/09/2026, où
     * 17 échecs ont chacun décompté une question.
     *
     * Rembourser APRÈS coup plutôt que de retarder le hit lui-même laisse le
     * mécanisme de blocage intact (un simple pré-contrôle en lecture,
     * `RateLimiter::attempts()`, jamais modifié ici) : la clé de cache est
     * EXACTEMENT celle que `ThrottleRequests` vient d'incrémenter
     * (`AiUserQuotaTier::cacheKey`), donc ce remboursement annule pile
     * l'effet de ce hit précis.
     *
     * Ne s'applique jamais à une requête déjà couverte par un crédit ($id
     * non nul, posé par le limiteur quand `CreditLedger::consume()` a
     * réussi) : cette requête n'a jamais fait bouger ce compteur, l'omettre
     * du retour de la fermeture `RateLimiter::for('ai_assistant', …)` est ce
     * qui l'a laissée passer sans hit.
     */
    private function refundQuota(User $user): void
    {
        $tier = AiUserQuotaTier::tierFor($user);
        ['scope' => $scope] = AiUserQuotaTier::tierDefinition($tier);

        RateLimiter::decrement(
            AiUserQuotaTier::cacheKey($user, $scope),
            $scope === 'month' ? 60 * 60 * 24 * 30 : 60 * 60 * 24,
        );
    }

    /**
     * mibeko-dashboard#84 : un message d'exception HTTP peut embarquer la
     * requête sortante entière (Guzzle notamment), donc un jeton d'API — on
     * retire ce qui y ressemble AVANT de tronquer, la longueur seule ne
     * suffit pas à l'exclure si le secret arrive tôt dans le message. Cette
     * table est déjà consultée par plusieurs profils en lecture seule.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Le jeton d'abord (« Bearer sk-... » sans forcément être précédé de
        // « Authorization: »), sinon le second remplacement ne verrait plus
        // que « Bearer » — le jeton lui-même resterait exposé après coup.
        $scrubbed = preg_replace('/bearer\s+\S+/i', 'Bearer [retiré]', $message) ?? $message;
        // Filet plus large sur l'en-tête complet, au cas où un autre schéma
        // (Basic, une clé brute…) suit « Authorization: ».
        $scrubbed = preg_replace('/(authorization:\s*)\S+(?:\s+\S+)?/i', '$1[retiré]', $scrubbed) ?? $scrubbed;

        return mb_substr($scrubbed, 0, 500);
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
