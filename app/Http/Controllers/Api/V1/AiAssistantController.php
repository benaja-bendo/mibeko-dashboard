<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Agents\MibekoIA;
use App\Http\Controllers\Controller;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Traits\SearchesArticles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $conversation = AgentConversation::with('messages')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

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

        // RAG: Search for relevant articles
        $sources = $this->searchArticles($userMessage);

        $promptContext = '';
        if (!empty($sources)) {
            $context = '';
            foreach ($sources as $index => $article) {
                $context .= '--- SOURCE '.($index + 1)." ---\n";
                $context .= 'Document : '.$article['document_title']."\n";
                $context .= 'Article : '.$article['number']."\n";
                $context .= 'Contenu : '.$article['content']."\n\n";
            }
            $promptContext = "Voici les extraits de loi pertinents trouvés dans la base Mibeko :\n\n"
                           . $context
                           . "Question de l'utilisateur : " . $userMessage;
        } else {
            $promptContext = $userMessage; // Let the agent decide to say it has no info based on instructions
        }

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
                ->then(function (StreamedAgentResponse $response) use ($sources) {
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
                });

            return response()->stream(function () use ($agentResponse, $sources) {
                try {
                    if (!empty($sources)) {
                        echo "event: sources\n";
                        echo "data: " . json_encode($sources) . "\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }

                    foreach ($agentResponse as $event) {
                        echo "data: " . ((string) $event) . "\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
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
                'Cache-Control' => 'no-cache',
                'Content-Type' => 'text/event-stream',
            ]);
        }

        $response = $agent->prompt($promptContext);

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

        return response()->json([
            'conversation_id' => $response->conversationId,
            'reply' => $response->text,
            'sources' => $sources,
        ]);
    }
}
