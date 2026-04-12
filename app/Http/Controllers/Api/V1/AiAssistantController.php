<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Agents\MibekoIA;
use App\Http\Controllers\Controller;
use App\Models\AgentConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Responses\StreamedAgentResponse;

/**
 * @tags Assistant IA
 */
class AiAssistantController extends Controller
{
    /**
     * Liste des conversations
     *
     * Retourne la liste paginée des conversations de l'utilisateur avec Mibeko IA.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = AgentConversation::where('user_id', $request->user()->id)
            ->orderBy('updated_at', 'desc')
            ->paginate(20);

        return response()->json($conversations);
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

        if ($stream) {
            if (!$id) {
                $conversation = AgentConversation::create([
                    'user_id' => $user->id,
                    'title' => str($request->input('message'))->limit(50, '...'),
                ]);
                $id = $conversation->id;
                $agent->continue($id, as: $user);
            }

            $agentResponse = $agent->stream($request->input('message'))
                ->then(function (StreamedAgentResponse $response) {
                    // Title already set above
                });

            /** @var \Symfony\Component\HttpFoundation\Response $httpResponse */
            $httpResponse = $agentResponse->toResponse($request);
            $httpResponse->headers->set('X-Conversation-Id', $id);
            $httpResponse->headers->set('X-Accel-Buffering', 'no');
            $httpResponse->headers->set('Cache-Control', 'no-cache');
            $httpResponse->headers->set('Content-Type', 'text/event-stream');
            return $httpResponse;
        }

        $response = $agent->prompt($request->input('message'));

        // Mettre à jour le titre de la conversation si c'est le premier message
        if (! $id) {
            $conversation = AgentConversation::find($response->conversationId);
            if ($conversation && empty($conversation->title)) {
                $conversation->update([
                    'title' => str($request->input('message'))->limit(50, '...'),
                ]);
            }
        }

        return response()->json([
            'conversation_id' => $response->conversationId,
            'reply' => $response->text,
        ]);
    }
}
