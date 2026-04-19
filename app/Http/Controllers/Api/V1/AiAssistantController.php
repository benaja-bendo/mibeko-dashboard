<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Agents\MibekoIA;
use App\Http\Controllers\Controller;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Traits\SearchesArticles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @tags Assistant IA
 */
class AiAssistantController extends Controller
{
    use SearchesArticles;

    /**
     * Liste des conversations
     *
     * Retourne la liste paginée des conversations de l'utilisateur avec Mibeko IA.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AgentConversation::where('user_id', $request->user()->id);

        if ($request->has('filter.date') && !empty($request->input('filter.date'))) {
            $date = $request->input('filter.date');
            // Assuming format is YYYY-MM-DD
            $query->whereDate('updated_at', $date);
        }

        if ($request->has('filter.title') && !empty($request->input('filter.title'))) {
            $title = $request->input('filter.title');
            $query->where('title', 'ilike', '%' . $title . '%');
        }

        $conversations = $query->orderBy('updated_at', 'desc')->paginate(20);

        return response()->json($conversations);
    }

    /**
     * Modifier une conversation
     *
     * Permet de modifier le titre d'une conversation.
     *
     * @param  string  $id  L'identifiant de la conversation
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $conversation = AgentConversation::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $conversation->update([
            'title' => $request->input('title'),
        ]);

        return response()->json($conversation);
    }

    /**
     * Détails d'une conversation
     *
     * Retourne les détails d'une conversation spécifique, y compris tous ses messages.
     *
     * @param  string  $id  L'identifiant de la conversation
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $conversation = AgentConversation::with(['messages' => function ($query) {
            $query->orderBy('created_at', 'asc');
        }])
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Nettoyer le contexte RAG des anciens messages pour l'affichage
        $conversation->messages->transform(function ($message) {
            if ($message->role === 'user') {
                $pattern = '/Voici les extraits de loi pertinents trouvés dans la base Mibeko :\s*.*Question de l\'utilisateur : /s';
                $message->content = preg_replace($pattern, '', $message->content);
            }
            return $message;
        });

        return response()->json($conversation);
    }

    /**
     * Supprimer une conversation
     *
     * Supprime une conversation spécifique et tous ses messages.
     *
     * @param  string  $id  L'identifiant de la conversation
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $conversation = AgentConversation::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $conversation->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Discuter avec Mibeko IA
     *
     * Envoie un message à l'assistant IA. Si aucun ID de conversation n'est fourni,
     * une nouvelle conversation est créée.
     *
     * @param  string|null  $id  L'identifiant de la conversation existante (optionnel)
     */
    public function chat(Request $request, ?string $id = null)
    {
        $request->validate([
            'message' => ['required', 'string'],
            'stream' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $agent = new MibekoIA;

        if ($id) {
            // Vérifier que la conversation appartient à l'utilisateur
            $conversation = AgentConversation::where('user_id', $user->id)->findOrFail($id);
            $agent->continue($conversation->id, as: $user);
        } else {
            $agent->forUser($user);
        }

        $stream = $request->boolean('stream', false);
        $userMessage = $request->input('message');

        // Implémentation du Semantic Caching (Mise en cache basée sur le message)
        // Note: Dans une vraie implémentation sémantique, on utiliserait un vecteur de similarité
        // Ici on utilise un cache simple basé sur le hash du message normalisé pour éviter les appels répétés
        $normalizedMessage = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $userMessage)));
        $cacheKey = 'ai_response_' . md5($normalizedMessage);

        if (!$id && Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);

            // Créer une nouvelle conversation pour l'historique de l'utilisateur
            $conversation = AgentConversation::create([
                'user_id' => $user->id,
                'title' => str($userMessage)->limit(50, '...'),
            ]);

            AgentConversationMessage::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'agent' => MibekoIA::class,
                'role' => 'user',
                'content' => $userMessage,
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
            ]);

            AgentConversationMessage::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'agent' => MibekoIA::class,
                'role' => 'assistant',
                'content' => $cachedResponse['reply'],
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => json_encode(['sources' => $cachedResponse['sources'], 'cached' => true]),
            ]);

            if ($stream) {
                return response()->stream(function () use ($cachedResponse) {
                    if (!empty($cachedResponse['sources'])) {
                        echo "event: sources\n";
                        echo "data: " . json_encode($cachedResponse['sources']) . "\n\n";
                        if (ob_get_level() > 0) ob_flush(); flush();
                    }

                    // Simuler un effet de stream
                    $chunks = mb_str_split($cachedResponse['reply'], 10, 'UTF-8');
                    foreach ($chunks as $chunk) {
                        $payload = [
                            'type' => 'text_delta',
                            'delta' => $chunk
                        ];
                        // S'assurer que le JSON est valide
                        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                        if ($jsonPayload) {
                            echo "data: " . $jsonPayload . "\n\n";
                            if (ob_get_level() > 0) ob_flush(); flush();
                            usleep(10000); // 10ms pause
                        }
                    }

                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush(); flush();
                }, 200, [
                    'X-Conversation-Id' => $conversation->id,
                    'X-Accel-Buffering' => 'no',
                    'Cache-Control' => 'no-cache, must-revalidate',
                    'Content-Type' => 'text/event-stream',
                    'Connection' => 'keep-alive',
                ]);
            }

            return response()->json([
                'conversation_id' => $conversation->id,
                'reply' => $cachedResponse['reply'],
                'sources' => $cachedResponse['sources'],
                'cached' => true
            ]);
        }

        // Agentic RAG: L'IA décide d'utiliser l'outil 'SearchLegalDatabase' si nécessaire.
        $promptContext = $userMessage;

        if ($stream) {
            if (! $id) {
                $conversation = AgentConversation::create([
                    'user_id' => $user->id,
                    'title' => str($userMessage)->limit(50, '...'),
                ]);
                $id = $conversation->id;
                $agent->continue($id, as: $user);
            }

            $agentResponse = $agent->stream($promptContext)
                ->then(function (StreamedAgentResponse $response) use ($userMessage, $cacheKey) {
                    // Nettoyer le message utilisateur pour ne pas polluer l'historique de l'IA
                    $lastUserMessage = AgentConversationMessage::where('conversation_id', $response->conversationId)
                        ->where('role', 'user')
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if ($lastUserMessage && $lastUserMessage->content !== $userMessage) {
                        $lastUserMessage->content = $userMessage;
                        $lastUserMessage->save();
                    }

                    // Extraire les sources du ToolResult s'il y en a un
                    $sources = [];
                    $toolResults = collect($response->events)->whereInstanceOf(\Laravel\Ai\Streaming\Events\ToolResult::class);
                    $searchResult = $toolResults->firstWhere('toolResult.name', 'SearchLegalDatabase');
                    if ($searchResult) {
                        $sources = json_decode($searchResult->toolResult->result, true) ?: [];
                    }

                    // Update the last assistant message to include sources in metadata
                    $lastMessage = AgentConversationMessage::where('conversation_id', $response->conversationId)
                        ->where('role', 'assistant')
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if ($lastMessage && !empty($sources)) {
                        $meta = is_array($lastMessage->meta) ? $lastMessage->meta : [];
                        $meta['sources'] = $sources;
                        $lastMessage->meta = $meta;
                        $lastMessage->save();
                    }

                    // Mettre en cache la réponse pour de futures requêtes identiques
                    Cache::put($cacheKey, [
                        'reply' => $response->text,
                        'sources' => $sources
                    ], now()->addHours(24));
                });

            return response()->stream(function () use ($agentResponse) {
                try {
                    foreach ($agentResponse as $event) {
                        // Intercepter les résultats de recherche pour les envoyer au mobile
                        if ($event instanceof \Laravel\Ai\Streaming\Events\ToolCall && $event->toolCall->name === 'SearchLegalDatabase') {
                            $payload = [
                                'type' => 'status',
                                'message' => 'Recherche dans la base de données juridique...'
                            ];
                            echo "event: status\n";
                            echo "data: " . json_encode($payload) . "\n\n";
                            if (ob_get_level() > 0) ob_flush(); flush();
                        }

                        if ($event instanceof \Laravel\Ai\Streaming\Events\ToolResult && $event->toolResult->name === 'SearchLegalDatabase') {
                            $sources = json_decode($event->toolResult->result, true) ?: [];
                            if (!empty($sources)) {
                                echo "event: sources\n";
                                echo "data: " . json_encode($sources) . "\n\n";
                                if (ob_get_level() > 0) {
                                    ob_flush();
                                }
                                flush();
                            }
                        }

                        if ($event instanceof \Laravel\Ai\Streaming\Events\TextDelta) {
                            $payload = [
                                'type' => 'text_delta',
                                'delta' => $event->delta
                            ];
                            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                            if ($jsonPayload) {
                                echo "data: " . $jsonPayload . "\n\n";
                                if (ob_get_level() > 0) ob_flush(); flush();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);

                    $payload = [
                        'message' => config('app.debug')
                            ? $e->getMessage()
                            : "Une erreur est survenue lors de la génération de la réponse.",
                    ];

                    echo "event: error\n";
                    echo "data: " . json_encode($payload) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                } finally {
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }, 200, [
                'X-Conversation-Id' => $id,
                'X-Accel-Buffering' => 'no',
                'Cache-Control' => 'no-cache, must-revalidate',
                'Content-Type' => 'text/event-stream',
                'Connection' => 'keep-alive',
            ]);
        }

        $response = $agent->prompt($promptContext);

        // Extraire les sources du ToolResult pour les réponses non streamées
        $sources = [];
        if ($response->toolResults) {
            $searchResult = collect($response->toolResults)->firstWhere('name', 'SearchLegalDatabase');
            if ($searchResult instanceof \Laravel\Ai\Responses\Data\ToolResult) {
                $sources = json_decode($searchResult->result, true) ?: [];
            }
        }

        // Nettoyer le message utilisateur pour ne pas polluer l'historique de l'IA
        $lastUserMessage = AgentConversationMessage::where('conversation_id', $response->conversationId)
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastUserMessage && $lastUserMessage->content !== $userMessage) {
            $lastUserMessage->content = $userMessage;
            $lastUserMessage->save();
        }

        // Update the last assistant message with sources if not streaming
        if (!empty($sources)) {
            $lastMessage = AgentConversationMessage::where('conversation_id', $response->conversationId)
                ->where('role', 'assistant')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($lastMessage) {
                $meta = is_array($lastMessage->meta) ? $lastMessage->meta : [];
                $meta['sources'] = $sources;
                $lastMessage->meta = $meta;
                $lastMessage->save();
            }
        }

        // Mettre à jour le titre de la conversation si c'est le premier message
        if (! $id) {
            $conversation = AgentConversation::find($response->conversationId);
            if ($conversation && empty($conversation->title)) {
                $conversation->update([
                    'title' => str($userMessage)->limit(50, '...'),
                ]);
            }
        }

        // Mettre en cache la réponse non streamée
        Cache::put($cacheKey, [
            'reply' => $response->text,
            'sources' => $sources
        ], now()->addHours(24));

        return response()->json([
            'conversation_id' => $response->conversationId,
            'reply' => $response->text,
            'sources' => $sources,
        ]);
    }
}
