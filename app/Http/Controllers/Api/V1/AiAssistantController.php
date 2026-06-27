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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
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

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
            'messages' => $messages,
        ]);
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
        // Ici on utilise un cache simple basé sur le hash du message normalisé pour éviter les appels répétés.
        // Le mode et les références épinglées changent la réponse : ils font partie de la clé.
        $normalizedMessage = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $userMessage)));
        $cacheKey = 'ai_response_'.md5($normalizedMessage.'|'.$mode.'|'.implode(',', array_column($references, 'id')));

        // Méta du message utilisateur : restitue les références/mode dans l'historique.
        $userMeta = array_filter([
            'mode' => $mode === MibekoIA::MODE_CONCISE ? null : $mode,
            'references' => $references ?: null,
        ]);

        if (! $id && Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);

            // Créer une nouvelle conversation pour l'historique de l'utilisateur
            $conversation = AgentConversation::create([
                'user_id' => $user->id,
                'title' => str($userMessage)->limit(50, '...'),
            ]);

            // Les colonnes castées en array reçoivent des tableaux PHP (jamais de
            // JSON pré-encodé, sinon double encodage à la lecture de l'historique).
            // Les id sont des UUID v7 (et non v4) : monotones donc chronologiques,
            // ils garantissent que la question précède la réponse à la relecture.
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

            AgentConversationMessage::create([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'agent' => MibekoIA::class,
                'role' => 'assistant',
                'content' => $cachedResponse['reply'],
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => ['sources' => $cachedResponse['sources'], 'cached' => true],
            ]);

            if ($stream) {
                return response()->stream(function () use ($cachedResponse) {
                    if (! empty($cachedResponse['sources'])) {
                        echo "event: sources\n";
                        echo 'data: '.json_encode($cachedResponse['sources'])."\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        } flush();
                    }

                    // Effet « machine à écrire » léger : une réponse en cache
                    // doit rester quasi instantanée. Des fragments plus larges et
                    // une pause courte gardent le rendu fluide sans réintroduire
                    // une latence sensible (≈ 0,3 s même sur une longue réponse).
                    $chunks = mb_str_split($cachedResponse['reply'], 24, 'UTF-8');
                    foreach ($chunks as $chunk) {
                        $payload = [
                            'type' => 'text_delta',
                            'delta' => $chunk,
                        ];
                        // S'assurer que le JSON est valide
                        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                        if ($jsonPayload) {
                            echo 'data: '.$jsonPayload."\n\n";
                            if (ob_get_level() > 0) {
                                ob_flush();
                            } flush();
                            usleep(5000); // 5ms
                        }
                    }

                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    } flush();
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
                'cached' => true,
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
                ->then(function (StreamedAgentResponse $response) use ($userMessage, $userMeta, $cacheKey) {
                    // Nettoyer le message utilisateur pour ne pas polluer l'historique de l'IA
                    $lastUserMessage = AgentConversationMessage::where('conversation_id', $response->conversationId)
                        ->where('role', 'user')
                        ->orderBy('created_at', 'desc')
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

                    // Concaténer les sources de TOUS les appels de l'outil de recherche,
                    // dans l'ordre : l'index (1-based) correspond au source_number cité [n].
                    $sources = collect($response->events)
                        ->whereInstanceOf(ToolResult::class)
                        ->filter(fn ($event) => $event->toolResult->name === 'SearchLegalDatabase')
                        ->flatMap(fn ($event) => json_decode($event->toolResult->result, true) ?: [])
                        ->values()
                        ->all();

                    // Update the last assistant message to include sources in metadata
                    $lastMessage = AgentConversationMessage::where('conversation_id', $response->conversationId)
                        ->where('role', 'assistant')
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if ($lastMessage && ! empty($sources)) {
                        $meta = is_array($lastMessage->meta) ? $lastMessage->meta : [];
                        $meta['sources'] = $sources;
                        $lastMessage->meta = $meta;
                        $lastMessage->save();
                    }

                    // Mettre en cache la réponse pour de futures requêtes identiques
                    Cache::put($cacheKey, [
                        'reply' => $response->text,
                        'sources' => $sources,
                    ], now()->addHours(24));
                });

            return response()->stream(function () use ($agentResponse) {
                // La persistance (question + réponse) a lieu dans le callback
                // `then()` du package, déclenché à la FIN de l'itération du flux.
                // Sans cela, une déconnexion client (onglet fermé, bouton Stop,
                // réseau coupé) tuerait le script au prochain flush() et ferait
                // perdre TOUT le tour — laissant une conversation vide à la
                // relecture. On ignore donc l'abandon : le flux est consommé
                // jusqu'au bout côté serveur et le tour est toujours enregistré.
                ignore_user_abort(true);

                try {
                    foreach ($agentResponse as $event) {
                        // Intercepter les résultats de recherche pour les envoyer au mobile
                        if ($event instanceof ToolCall && $event->toolCall->name === 'SearchLegalDatabase') {
                            $payload = [
                                'type' => 'status',
                                'message' => 'Recherche dans la base de données juridique...',
                            ];
                            echo "event: status\n";
                            echo 'data: '.json_encode($payload)."\n\n";
                            if (ob_get_level() > 0) {
                                ob_flush();
                            } flush();
                        }

                        if ($event instanceof ToolResult && $event->toolResult->name === 'SearchLegalDatabase') {
                            $sources = json_decode($event->toolResult->result, true) ?: [];
                            if (! empty($sources)) {
                                echo "event: sources\n";
                                echo 'data: '.json_encode($sources)."\n\n";
                                if (ob_get_level() > 0) {
                                    ob_flush();
                                }
                                flush();
                            }
                        }

                        if ($event instanceof TextDelta) {
                            $payload = [
                                'type' => 'text_delta',
                                'delta' => $event->delta,
                            ];
                            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
                            if ($jsonPayload) {
                                echo 'data: '.$jsonPayload."\n\n";
                                if (ob_get_level() > 0) {
                                    ob_flush();
                                } flush();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);

                    $payload = [
                        'message' => config('app.debug')
                            ? $e->getMessage()
                            : 'Une erreur est survenue lors de la génération de la réponse.',
                    ];

                    echo "event: error\n";
                    echo 'data: '.json_encode($payload)."\n\n";
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

        // Concaténer les sources de TOUS les appels de l'outil (réponses non streamées)
        $sources = collect($response->toolResults ?? [])
            ->filter(fn ($result) => $result instanceof \Laravel\Ai\Responses\Data\ToolResult
                && $result->name === 'SearchLegalDatabase')
            ->flatMap(fn ($result) => json_decode($result->result, true) ?: [])
            ->values()
            ->all();

        // Nettoyer le message utilisateur pour ne pas polluer l'historique de l'IA
        $lastUserMessage = AgentConversationMessage::where('conversation_id', $response->conversationId)
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
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

        // Update the last assistant message with sources if not streaming
        if (! empty($sources)) {
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
            'sources' => $sources,
        ], now()->addHours(24));

        return response()->json([
            'conversation_id' => $response->conversationId,
            'reply' => $response->text,
            'sources' => $sources,
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
