<?php

namespace App\Ai;

use App\Ai\Agents\MibekoIA;
use App\Jobs\GenerateConversationTitle;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

/**
 * Logique métier partagée des échanges avec Mibeko IA.
 *
 * Mutualise ce que les trois voies du contrôleur (réponse en cache, streaming
 * SSE, réponse synchrone) faisaient en triple : clé de cache, méta du message,
 * extraction des sources, finalisation du tour persisté et mise en cache. Le
 * contrôleur ne garde plus que l'orchestration HTTP.
 */
class AssistantChatService
{
    /** Nom de l'outil de recherche dont les résultats deviennent des citations. */
    public const SEARCH_TOOL = 'SearchLegalDatabase';

    /** Durée de vie du cache des réponses (invalidé avant terme si le corpus change). */
    public const CACHE_TTL_HOURS = 24;

    /**
     * Clé de cache d'une réponse : message normalisé + mode + références + version
     * du corpus (toute évolution des textes publiés invalide les réponses).
     *
     * @param  array<int, array{id: string, title: string}>  $references
     */
    public function cacheKey(string $userMessage, string $mode, array $references): string
    {
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $userMessage)));

        return 'ai_response_'.md5(
            $normalized.'|'.$mode.'|'.implode(',', array_column($references, 'id')).'|'.CorpusVersion::current()
        );
    }

    /**
     * Méta du message utilisateur : restitue mode et références dans l'historique.
     *
     * @param  array<int, array{id: string, title: string}>  $references
     * @return array<string, mixed>
     */
    public function userMeta(string $mode, array $references): array
    {
        return array_filter([
            'mode' => $mode === MibekoIA::MODE_CONCISE ? null : $mode,
            'references' => $references ?: null,
        ]);
    }

    /**
     * Crée une conversation (titre tronqué provisoire) et programme la génération
     * d'un vrai titre IA en tâche de fond.
     */
    public function createConversation(User $user, string $userMessage): AgentConversation
    {
        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'title' => str($userMessage)->limit(50, '...'),
        ]);

        GenerateConversationTitle::dispatch($conversation->id, $userMessage);

        return $conversation;
    }

    /**
     * Écrit un tour complet (question + réponse) pour une réponse servie du cache.
     *
     * @param  array{reply: string, sources: array<int, mixed>}  $cached
     * @param  array<string, mixed>  $userMeta
     */
    public function writeCachedTurn(AgentConversation $conversation, User $user, string $userMessage, array $userMeta, array $cached): AgentConversationMessage
    {
        // Id en UUID v7 (chronologiques) et colonnes array en tableaux PHP : la
        // question précède la réponse à la relecture, sans double encodage.
        AgentConversationMessage::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => MibekoIA::class,
            'role' => 'user',
            'content' => $userMessage,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => $userMeta,
        ]);

        return AgentConversationMessage::create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => MibekoIA::class,
            'role' => 'assistant',
            'content' => $cached['reply'],
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => ['sources' => $cached['sources'], 'cached' => true],
        ]);
    }

    /**
     * Concatène, dans l'ordre, les sources de tous les appels de l'outil de
     * recherche d'un flux streamé : l'index 1-based correspond au marqueur [n].
     *
     * @param  iterable<int, mixed>  $events
     * @return array<int, mixed>
     */
    public function sourcesFromEvents(iterable $events): array
    {
        return collect($events)
            ->whereInstanceOf(ToolResultEvent::class)
            ->filter(fn ($event) => $event->toolResult->name === self::SEARCH_TOOL)
            ->flatMap(fn ($event) => json_decode($event->toolResult->result, true) ?: [])
            ->values()
            ->all();
    }

    /**
     * Idem pour une réponse synchrone (non streamée).
     *
     * @return array<int, mixed>
     */
    public function sourcesFromResponse(AgentResponse $response): array
    {
        return collect($response->toolResults ?? [])
            ->filter(fn ($result) => $result instanceof ToolResultData && $result->name === self::SEARCH_TOOL)
            ->flatMap(fn ($result) => json_decode($result->result, true) ?: [])
            ->values()
            ->all();
    }

    /**
     * Finalise un tour : recale le dernier message utilisateur (nettoyage du
     * contexte RAG, méta) et attache les sources au dernier message assistant.
     *
     * @param  array<string, mixed>  $userMeta
     * @param  array<int, mixed>  $sources
     * @return string|null Id du message assistant (pour le feedback immédiat).
     */
    public function finalizeTurn(string $conversationId, string $userMessage, array $userMeta, array $sources): ?string
    {
        $lastUserMessage = AgentConversationMessage::where('conversation_id', $conversationId)
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($lastUserMessage) {
            if ($lastUserMessage->content !== $userMessage) {
                $lastUserMessage->content = $userMessage;
            }
            if (! empty($userMeta)) {
                $meta = is_array($lastUserMessage->meta) ? $lastUserMessage->meta : [];
                $lastUserMessage->meta = array_merge($meta, $userMeta);
            }
            $lastUserMessage->save();
        }

        $lastAssistant = AgentConversationMessage::where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($lastAssistant && ! empty($sources)) {
            $meta = is_array($lastAssistant->meta) ? $lastAssistant->meta : [];
            $meta['sources'] = $sources;
            $lastAssistant->meta = $meta;
            $lastAssistant->save();
        }

        return $lastAssistant?->id;
    }

    /**
     * Met une réponse en cache pour les requêtes identiques ultérieures.
     *
     * @param  array<int, mixed>  $sources
     */
    public function cacheResponse(string $cacheKey, string $reply, array $sources): void
    {
        Cache::put($cacheKey, [
            'reply' => $reply,
            'sources' => $sources,
        ], now()->addHours(self::CACHE_TTL_HOURS));
    }
}
