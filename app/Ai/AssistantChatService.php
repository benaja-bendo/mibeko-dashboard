<?php

namespace App\Ai;

use App\Ai\Agents\MibekoIA;
use App\Ai\Tools\SearchLegalDatabase;
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
            'meta' => array_filter([
                'sources' => $cached['sources'],
                'cached' => true,
                // Reportée telle quelle : une non-réponse resservie du cache reste
                // une non-réponse dans l'historique comme à l'écran.
                'no_result' => $cached['no_result'] ?? false,
            ], fn ($valeur) => $valeur !== false && $valeur !== null),
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
            ->flatMap(fn ($event) => SearchLegalDatabase::extractsFrom($event->toolResult->result))
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
            ->flatMap(fn ($result) => SearchLegalDatabase::extractsFrom($result->result))
            ->values()
            ->all();
    }

    /**
     * Le tour a-t-il réellement interrogé le corpus ?
     *
     * Distingue « l'assistant a cherché et n'a rien trouvé » (non-réponse à
     * afficher comme telle) de « l'assistant n'avait pas à chercher » (salutation,
     * demande de reformulation) — sans quoi le second se verrait étiqueté à tort
     * comme une absence de texte.
     *
     * @param  iterable<int, mixed>  $events
     */
    public function searchRanInEvents(iterable $events): bool
    {
        return collect($events)
            ->whereInstanceOf(ToolResultEvent::class)
            ->contains(fn ($event) => $event->toolResult->name === self::SEARCH_TOOL);
    }

    public function searchRanInResponse(AgentResponse $response): bool
    {
        return collect($response->toolResults ?? [])
            ->contains(fn ($result) => $result instanceof ToolResultData && $result->name === self::SEARCH_TOOL);
    }

    /**
     * Finalise un tour : recale le dernier message utilisateur (nettoyage du
     * contexte RAG, méta), attache les sources au dernier message assistant et
     * neutralise ses éventuels marqueurs de citation orphelins.
     *
     * @param  array<string, mixed>  $userMeta
     * @param  array<int, mixed>  $sources
     * @return string|null Id du message assistant (pour le feedback immédiat).
     */
    public function finalizeTurn(string $conversationId, string $userMessage, array $userMeta, array $sources, bool $noResult = false): ?string
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

        if ($lastAssistant) {
            $dirty = false;

            // Retire du texte persisté les marqueurs [n] sans source réelle : la
            // relecture de l'historique (et le rejeu au modèle) ne doit jamais
            // exhiber une référence hallucinée.
            $verified = $this->verifyCitations((string) $lastAssistant->content, $sources);
            if ($verified !== $lastAssistant->content) {
                $lastAssistant->content = $verified;
                $dirty = true;
            }

            if (! empty($sources)) {
                $meta = is_array($lastAssistant->meta) ? $lastAssistant->meta : [];
                $meta['sources'] = $sources;
                $lastAssistant->meta = $meta;
                $dirty = true;
            }

            // Une non-réponse relue dans l'historique doit rester une non-réponse :
            // sans cette marque, le fil rechargé afficherait une réponse ordinaire
            // dépourvue de sources, indistinguable d'une réponse tronquée.
            if ($noResult) {
                $meta = is_array($lastAssistant->meta) ? $lastAssistant->meta : [];
                $meta['no_result'] = true;
                $lastAssistant->meta = $meta;
                $dirty = true;
            }

            if ($dirty) {
                $lastAssistant->save();
            }
        }

        return $lastAssistant?->id;
    }

    /**
     * Met une réponse en cache pour les requêtes identiques ultérieures.
     *
     * @param  array<int, mixed>  $sources
     */
    public function cacheResponse(string $cacheKey, string $reply, array $sources, bool $noResult = false): void
    {
        Cache::put($cacheKey, [
            'reply' => $reply,
            'sources' => $sources,
            'no_result' => $noResult,
        ], now()->addHours(self::CACHE_TTL_HOURS));
    }

    /**
     * Neutralise les marqueurs de citation [n] orphelins d'une réponse.
     *
     * Le modèle peut halluciner une référence : écrire « [5] » alors que seules
     * trois sources ont réellement été retournées par l'outil de recherche. Un
     * tel marqueur pointe vers le vide côté client (bouton mort sur le web,
     * « [5] » littéral sur mobile) et — plus grave — donne l'illusion d'un
     * fondement légal inexistant. La fiabilité juridique étant le cœur de valeur,
     * on retire ces marqueurs du texte AVANT persistance et mise en cache.
     *
     * On ne garde que les marqueurs dont le numéro correspond à une source
     * réellement fournie (champ `source_number`, sinon position 1-based dans le
     * tableau — les deux coïncident, l'outil numérotant les sources en continu).
     * Le retrait est purement textuel : le contrat SSE et la charge `sources` ne
     * changent pas (fix additif, aucun client existant cassé).
     *
     * L'espace éventuel qui précède un marqueur retiré est absorbé pour ne pas
     * laisser de double espace (« préavis  ») ni d'espace avant ponctuation.
     *
     * @param  array<int, mixed>  $sources  Sources réellement retournées par le RAG.
     */
    public function verifyCitations(string $reply, array $sources): string
    {
        // Aucune source : aucun marqueur ne peut être fondé, on les retire tous.
        // Une source sans `source_number` retombe sur sa position 1-based.
        $validNumbers = [];
        foreach (array_values($sources) as $position => $source) {
            $number = is_array($source) && isset($source['source_number'])
                ? (int) $source['source_number']
                : $position + 1;
            $validNumbers[$number] = true;
        }

        // On n'absorbe qu'UN espace horizontal avant le marqueur (jamais un saut
        // de ligne), à l'identique du filtre SSE : retirer un « \n » fusionnerait
        // un titre markdown avec le corps qui suit (« ## Titre\n[9] … »).
        return preg_replace_callback(
            '/[ \t]?\[(\d+)\]/',
            function (array $matches) use ($validNumbers): string {
                // Marqueur fondé : on le conserve tel quel (espace inclus).
                if (isset($validNumbers[(int) $matches[1]])) {
                    return $matches[0];
                }

                // Marqueur orphelin : retiré avec l'espace horizontal qui le précédait.
                return '';
            },
            $reply,
        ) ?? $reply;
    }
}
