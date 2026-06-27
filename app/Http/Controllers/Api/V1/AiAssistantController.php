<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\Agents\MibekoIA;
use App\Ai\AssistantChatService;
use App\Http\Controllers\Controller;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\AgentMessageFeedback;
use App\Models\User;
use App\Support\ServerSentEvents;
use App\Traits\SearchesArticles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Assistant IA
 */
class AiAssistantController extends Controller
{
    use SearchesArticles;

    public function __construct(private readonly AssistantChatService $chatService) {}

    /**
     * Liste des conversations
     *
     * Retourne la liste paginée des conversations de l'utilisateur avec Mibeko IA.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AgentConversation::where('user_id', $request->user()->id)
            // N'expose que les conversations ayant réellement un échange : les
            // coquilles vides (échange interrompu très tôt, erreur fournisseur)
            // ne polluent pas l'historique et ne mènent jamais à un fil vide au
            // clic. Sous-requête EXISTS indexée sur conversation_id : négligeable.
            ->whereHas('messages');

        if ($request->has('filter.date') && ! empty($request->input('filter.date'))) {
            $date = $request->input('filter.date');
            // Assuming format is YYYY-MM-DD
            $query->whereDate('updated_at', $date);
        }

        if ($request->has('filter.title') && ! empty($request->input('filter.title'))) {
            $title = $request->input('filter.title');
            $query->where('title', 'ilike', '%'.$title.'%');
        }

        // Liste volontairement légère : l'historique n'a besoin d'aucun contenu.
        $conversations = $query->orderBy('updated_at', 'desc')
            ->paginate(20, ['id', 'title', 'created_at', 'updated_at']);

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
        $conversation = AgentConversation::where('user_id', $request->user()->id)
            ->findOrFail($id);

        // Charge uniquement les colonnes utiles à l'affichage : tool_results
        // contient le texte intégral des articles trouvés à chaque recherche et
        // peut peser des centaines de Ko sur une conversation — il ne doit
        // jamais transiter vers le client.
        // Les identifiants sont des UUID v7 (monotones, donc chronologiques).
        // created_at est tronqué à la seconde : la question et la réponse d'un
        // échange rapide partagent alors la même valeur et le tri devient non
        // déterministe (Postgres). On départage par id pour ne jamais afficher
        // la réponse avant la question — c'est aussi l'ordre que le package
        // laravel/ai utilise pour rejouer l'historique au modèle.
        $messages = AgentConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'meta', 'created_at'])
            ->map(function (AgentConversationMessage $message) {
                $content = $message->content;

                // Nettoyer le contexte RAG des anciens messages utilisateur.
                // Quantificateur paresseux (.*?) pour éviter le backtracking
                // catastrophique sur de gros messages legacy ; on retombe sur le
                // contenu d'origine si la regex échoue (jamais de null silencieux
                // qui supprimerait le message du fil).
                if ($message->role === 'user') {
                    $pattern = '/Voici les extraits de loi pertinents trouvés dans la base Mibeko :\s*.*?Question de l\'utilisateur : /s';
                    $content = preg_replace($pattern, '', $content) ?? $content;
                }

                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $content,
                    'meta' => $this->normalizeMessageMeta($message->meta),
                    'created_at' => $message->created_at?->toISOString(),
                ];
            })
            // Les tours « appel d'outil » de l'assistant n'ont pas de texte :
            // ils ne doivent pas produire de bulle vide dans le fil.
            ->filter(fn (array $message) => trim((string) $message['content']) !== '')
            ->values();

        // Avis 👍/👎 de l'utilisateur courant, pour restituer l'état des boutons
        // de feedback à la relecture (null = pas encore noté).
        $feedbackByMessage = AgentMessageFeedback::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('message_id', $messages->pluck('id'))
            ->pluck('rating', 'message_id');

        $messages = $messages
            ->map(fn (array $message) => [
                ...$message,
                'feedback' => $feedbackByMessage[$message['id']] ?? null,
            ])
            ->values();

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
            'messages' => $messages,
        ]);
    }

    /**
     * Noter une réponse de l'assistant
     *
     * Enregistre ou met à jour l'avis 👍/👎 de l'utilisateur sur un message de
     * l'assistant (un seul avis par utilisateur et par message).
     *
     * @param  string  $message  L'identifiant du message assistant
     */
    public function feedback(Request $request, string $message): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'string', 'in:up,down'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $target = $this->findOwnedAssistantMessage($request, $message);

        $feedback = AgentMessageFeedback::updateOrCreate(
            ['message_id' => $target->id, 'user_id' => $request->user()->id],
            ['rating' => $validated['rating'], 'comment' => $validated['comment'] ?? null],
        );

        return response()->json([
            'message_id' => $target->id,
            'rating' => $feedback->rating,
        ]);
    }

    /**
     * Retirer son avis sur une réponse de l'assistant
     *
     * @param  string  $message  L'identifiant du message assistant
     */
    public function deleteFeedback(Request $request, string $message): JsonResponse
    {
        $target = $this->findOwnedAssistantMessage($request, $message);

        AgentMessageFeedback::where('message_id', $target->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Récupère un message assistant appartenant à l'utilisateur courant, ou 404.
     *
     * La possession est vérifiée au niveau SQL (la conversation parente doit
     * être celle de l'utilisateur) : impossible de noter le message d'autrui.
     */
    private function findOwnedAssistantMessage(Request $request, string $messageId): AgentConversationMessage
    {
        return AgentConversationMessage::query()
            ->where('agent_conversation_messages.id', $messageId)
            ->where('role', 'assistant')
            ->whereExists(function ($query) use ($request) {
                $query->selectRaw('1')
                    ->from('agent_conversations')
                    ->whereColumn('agent_conversations.id', 'agent_conversation_messages.conversation_id')
                    ->where('agent_conversations.user_id', $request->user()->id);
            })
            ->firstOrFail();
    }

    /**
     * Normalise la méta d'un message pour l'affichage.
     *
     * D'anciens messages ont été enregistrés avec une méta doublement encodée
     * (JSON dans une colonne déjà castée array) : le cast les restitue alors en
     * chaîne. On les re-décode pour que `meta.sources` redevienne exploitable
     * par les citations cliquables du front.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeMessageMeta(mixed $meta): ?array
    {
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        return is_array($meta) && $meta !== [] ? $meta : null;
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
        $validated = $request->validate([
            'message' => ['required', 'string'],
            'stream' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', 'in:concise,analysis'],
            'references' => ['sometimes', 'array', 'max:5'],
            'references.*.id' => ['required', 'string'],
            'references.*.type' => ['sometimes', 'string', 'in:document'],
        ]);

        $user = $request->user();
        $mode = $validated['mode'] ?? MibekoIA::MODE_CONCISE;
        $references = $this->resolveReferences($validated['references'] ?? []);
        $agent = new MibekoIA(mode: $mode, scopedDocuments: $references);

        if ($id) {
            // Vérifier que la conversation appartient à l'utilisateur.
            $conversation = AgentConversation::where('user_id', $user->id)->findOrFail($id);
            $agent->continue($conversation->id, as: $user);
        } else {
            $agent->forUser($user);
        }

        $stream = $request->boolean('stream', false);
        $userMessage = $request->input('message');
        $cacheKey = $this->chatService->cacheKey($userMessage, $mode, $references);
        $userMeta = $this->chatService->userMeta($mode, $references);

        // 1) Réponse déjà en cache (uniquement pour une nouvelle conversation,
        //    donc sans contexte conversationnel).
        if (! $id && Cache::has($cacheKey)) {
            return $this->respondFromCache($user, Cache::get($cacheKey), $userMessage, $userMeta, $stream);
        }

        // 2) Streaming agentic (SSE) — le client streame toujours.
        if ($stream) {
            return $this->streamReply($agent, $user, $id, $userMessage, $userMeta, $cacheKey);
        }

        // 3) Réponse synchrone (JSON).
        return $this->syncReply($agent, $id, $userMessage, $userMeta, $cacheKey);
    }

    /**
     * Sert une réponse mémorisée en recréant un tour complet dans l'historique.
     *
     * @param  array{reply: string, sources: array<int, mixed>}  $cached
     * @param  array<string, mixed>  $userMeta
     */
    private function respondFromCache(User $user, array $cached, string $userMessage, array $userMeta, bool $stream)
    {
        $conversation = $this->chatService->createConversation($user, $userMessage);
        $assistantMessage = $this->chatService->writeCachedTurn($conversation, $user, $userMessage, $userMeta, $cached);

        if ($stream) {
            return $this->streamedResponse(function () use ($cached, $assistantMessage) {
                if (! empty($cached['sources'])) {
                    ServerSentEvents::send($cached['sources'], 'sources');
                }

                // Effet « machine à écrire » léger : une réponse en cache reste
                // quasi instantanée (≈ 0,3 s même sur une longue réponse).
                foreach (mb_str_split($cached['reply'], 24, 'UTF-8') as $chunk) {
                    ServerSentEvents::send(['type' => 'text_delta', 'delta' => $chunk]);
                    usleep(5000);
                }

                ServerSentEvents::send(['message_id' => $assistantMessage->id], 'meta');
                ServerSentEvents::done();
            }, $conversation->id);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'reply' => $cached['reply'],
            'sources' => $cached['sources'],
            'cached' => true,
        ]);
    }

    /**
     * Streame la réponse de l'agent (RAG agentic) en SSE.
     *
     * @param  array<string, mixed>  $userMeta
     */
    private function streamReply(MibekoIA $agent, User $user, ?string $id, string $userMessage, array $userMeta, string $cacheKey)
    {
        if (! $id) {
            $conversation = $this->chatService->createConversation($user, $userMessage);
            $id = $conversation->id;
            $agent->continue($id, as: $user);
        }

        // Id backend du message assistant, transmis au client en fin de flux pour
        // qu'il puisse noter immédiatement la réponse (feedback).
        $assistantMessageId = null;

        $agentResponse = $agent->stream($userMessage)->then(
            function (StreamedAgentResponse $response) use ($userMessage, $userMeta, $cacheKey, &$assistantMessageId) {
                $sources = $this->chatService->sourcesFromEvents($response->events);
                $assistantMessageId = $this->chatService->finalizeTurn($response->conversationId, $userMessage, $userMeta, $sources);
                $this->chatService->cacheResponse($cacheKey, $response->text, $sources);
            }
        );

        return $this->streamedResponse(function () use ($agentResponse, &$assistantMessageId) {
            // La persistance (question + réponse) a lieu dans le callback `then()`
            // du package, déclenché à la FIN de l'itération. Sans cela, une
            // déconnexion client (onglet fermé, Stop, réseau) tuerait le script au
            // prochain flush() et ferait perdre TOUT le tour. On ignore donc
            // l'abandon : le flux est consommé jusqu'au bout côté serveur.
            ignore_user_abort(true);

            try {
                foreach ($agentResponse as $event) {
                    if ($event instanceof ToolCall && $event->toolCall->name === AssistantChatService::SEARCH_TOOL) {
                        ServerSentEvents::send(
                            ['type' => 'status', 'message' => 'Recherche dans la base de données juridique...'],
                            'status'
                        );
                    }

                    if ($event instanceof ToolResult && $event->toolResult->name === AssistantChatService::SEARCH_TOOL) {
                        $sources = json_decode($event->toolResult->result, true) ?: [];
                        if (! empty($sources)) {
                            ServerSentEvents::send($sources, 'sources');
                        }
                    }

                    if ($event instanceof TextDelta) {
                        ServerSentEvents::send(['type' => 'text_delta', 'delta' => $event->delta]);
                    }
                }
            } catch (\Throwable $e) {
                report($e);

                ServerSentEvents::send([
                    'message' => config('app.debug')
                        ? $e->getMessage()
                        : 'Une erreur est survenue lors de la génération de la réponse.',
                ], 'error');
            } finally {
                // Id backend du message assistant : feedback immédiat possible.
                if ($assistantMessageId) {
                    ServerSentEvents::send(['message_id' => $assistantMessageId], 'meta');
                }

                ServerSentEvents::done();
            }
        }, $id);
    }

    /**
     * Réponse synchrone (JSON), sans streaming.
     *
     * @param  array<string, mixed>  $userMeta
     */
    private function syncReply(MibekoIA $agent, ?string $id, string $userMessage, array $userMeta, string $cacheKey): JsonResponse
    {
        $response = $agent->prompt($userMessage);
        $sources = $this->chatService->sourcesFromResponse($response);
        $this->chatService->finalizeTurn($response->conversationId, $userMessage, $userMeta, $sources);

        // Titre de repli si le package n'en a pas généré (première réponse).
        if (! $id) {
            $conversation = AgentConversation::find($response->conversationId);
            if ($conversation && empty($conversation->title)) {
                $conversation->update(['title' => str($userMessage)->limit(50, '...')]);
            }
        }

        $this->chatService->cacheResponse($cacheKey, $response->text, $sources);

        return response()->json([
            'conversation_id' => $response->conversationId,
            'reply' => $response->text,
            'sources' => $sources,
        ]);
    }

    /**
     * Réponse SSE standardisée (en-têtes anti-tampon).
     */
    private function streamedResponse(\Closure $callback, ?string $conversationId): StreamedResponse
    {
        return response()->stream($callback, 200, [
            'X-Conversation-Id' => $conversationId,
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Content-Type' => 'text/event-stream',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Rechercher des références épinglables
     *
     * Documents publiés proposés dans le sélecteur « @ » du composer : permet de
     * restreindre la recherche de l'IA à un ou plusieurs textes précis.
     */
    public function references(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));

        $documents = DB::table('legal_documents as ld')
            ->join('document_types as dt', 'ld.type_code', '=', 'dt.code')
            ->whereNull('ld.deleted_at')
            ->where('ld.curation_status', 'published')
            ->when($q !== '', fn ($query) => $query->where('ld.titre_officiel', 'ILIKE', "%{$q}%"))
            ->orderBy('dt.niveau_hierarchique')
            ->orderByDesc('ld.date_publication')
            ->limit(8)
            ->get([
                'ld.id',
                'ld.titre_officiel as title',
                'dt.code as type_code',
                'dt.nom as type_name',
            ]);

        return response()->json(['data' => $documents]);
    }

    /**
     * Résout les références épinglées en documents publiés exploitables.
     *
     * Les identifiants inconnus ou non publiés sont silencieusement écartés :
     * la requête reste valide, simplement sans ce périmètre.
     *
     * @param  array<int, array{id: string, type?: string}>  $references
     * @return array<int, array{id: string, title: string}>
     */
    private function resolveReferences(array $references): array
    {
        if ($references === []) {
            return [];
        }

        $ids = array_values(array_unique(array_column($references, 'id')));

        return DB::table('legal_documents')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->where('curation_status', 'published')
            ->get(['id', 'titre_officiel'])
            ->map(fn ($doc) => ['id' => $doc->id, 'title' => $doc->titre_officiel])
            ->all();
    }
}
