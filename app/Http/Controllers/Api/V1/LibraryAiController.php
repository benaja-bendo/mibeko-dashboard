<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\SearchesArticles;
use Illuminate\Http\Request;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function Laravel\Ai\agent;

/**
 * @group Library AI
 *
 * Couche IA « à la demande » de la Bibliothèque, déclenchée explicitement par
 * l'utilisateur (jamais automatiquement comme l'ancienne recherche). Deux
 * actions, toutes deux en streaming SSE et **sans état** (aucune conversation
 * n'est créée, contrairement à l'Assistant) :
 *  - `explain`   : explication pédagogique d'UN article ;
 *  - `synthesis` : synthèse RAG sur le top-K d'une recherche.
 *
 * Le protocole SSE est identique à celui de l'Assistant (`event: sources`,
 * `text_delta`, `event: error`, `[DONE]`) afin de réutiliser le client front.
 */
class LibraryAiController extends Controller
{
    use SearchesArticles;

    /**
     * Instructions système — cadre juridique strictement congolais, réponse
     * fondée uniquement sur les extraits fournis (pas d'outil, contexte injecté).
     */
    private const SYSTEM_PROMPT = "Tu es un expert juridique spécialisé EXCLUSIVEMENT dans le droit de la République du Congo (Congo-Brazzaville). Ton rôle est d'aider les citoyens à comprendre leurs droits de manière rigoureuse.\n\n"
        ."RÈGLES CRITIQUES :\n"
        ."1. Tu ne dois JAMAIS faire référence à la loi française ou à la loi d'un autre pays. Tout ton contexte juridique est celui de la République du Congo.\n"
        ."2. Réponds UNIQUEMENT en te basant sur les extraits de loi fournis.\n"
        ."3. N'utilise JAMAIS tes propres connaissances externes ou des informations qui ne sont pas dans les sources fournies.\n"
        ."4. Si les extraits fournis ne permettent pas de répondre précisément, indique clairement que tu ne trouves pas l'information dans la base de données Mibeko.\n"
        ."5. Pour chaque point de ta réponse, cite le document et le numéro d'article utilisé.\n"
        .'6. Garde un ton professionnel, neutre et pédagogique.';

    /**
     * Expliquer un article (streaming SSE).
     *
     * @bodyParam article_id string required UUID de l'article à expliquer.
     */
    public function explain(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'article_id' => ['required', 'string', 'exists:articles,id'],
        ]);

        $article = $this->fetchArticleContext($validated['article_id']);

        if (! $article) {
            return $this->streamError("Cet article n'est pas disponible dans la base Mibeko.");
        }

        $userPrompt = "Voici l'article de loi à expliquer (issu de la base Mibeko) :\n\n"
            .'Document : '.$article['document_title']."\n"
            .'Article : '.$article['number']."\n"
            .'Contenu : '.$article['content']."\n\n"
            ."Explique cet article de façon claire et pédagogique pour un citoyen : ce qu'il signifie concrètement, à qui il s'applique et ses implications pratiques. Cite le document et le numéro d'article.\n\n"
            .'Réponse (en français) :';

        return $this->streamAnswer($userPrompt, [$article]);
    }

    /**
     * Synthèse Mibeko IA d'une recherche (streaming SSE).
     *
     * Reprend le contexte top-K de la recherche full-text (mêmes résultats que
     * ceux affichés à l'utilisateur) puis le synthétise via l'agent.
     *
     * @bodyParam q string required Requête / sujet de l'utilisateur.
     */
    public function synthesis(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2'],
            'type' => ['nullable', 'string', 'exists:document_types,code'],
            'institution_id' => ['nullable', 'string', 'exists:institutions,id'],
            'legal_scope' => ['nullable', 'string', 'in:national,ohada,communautaire'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'document_id' => ['nullable', 'string', 'exists:legal_documents,id'],
        ]);

        $sources = $this->lexicalArticleContext($validated['q'], [
            'type' => $validated['type'] ?? null,
            'institution_id' => $validated['institution_id'] ?? null,
            'legal_scope' => $validated['legal_scope'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'document_id' => $validated['document_id'] ?? null,
        ], 5);

        if (empty($sources)) {
            return $this->streamError("Aucun texte pertinent dans la base Mibeko pour cette recherche. La base est limitée aux textes officiels de la République du Congo intégrés à l'application.");
        }

        $context = '';
        foreach ($sources as $index => $article) {
            $context .= '--- SOURCE '.($index + 1)." ---\n";
            $context .= 'Document : '.$article['document_title']."\n";
            $context .= 'Article : '.$article['number']."\n";
            $context .= 'Contenu : '.$article['content']."\n\n";
        }

        $userPrompt = "Voici les extraits de loi pertinents trouvés dans la base Mibeko :\n\n"
            .$context
            ."Sujet / question de l'utilisateur : ".$validated['q']."\n\n"
            ."Fais une synthèse claire et structurée en t'appuyant UNIQUEMENT sur ces extraits, en citant les articles concernés.\n\n"
            .'Réponse (en français) :';

        return $this->streamAnswer($userPrompt, $sources);
    }

    /**
     * Stream an agent answer as SSE, preceded by its source list.
     *
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function streamAnswer(string $userPrompt, array $sources): StreamedResponse
    {
        return response()->stream(function () use ($userPrompt, $sources) {
            // Les sources d'abord : mêmes citations cliquables que l'Assistant.
            echo "event: sources\n";
            echo 'data: '.json_encode($sources, JSON_UNESCAPED_UNICODE)."\n\n";
            $this->flushSse();

            try {
                foreach (agent(instructions: self::SYSTEM_PROMPT)->stream($userPrompt) as $event) {
                    if ($event instanceof TextDelta) {
                        $payload = json_encode(['type' => 'text_delta', 'delta' => $event->delta], JSON_UNESCAPED_UNICODE);
                        if ($payload) {
                            echo 'data: '.$payload."\n\n";
                            $this->flushSse();
                        }
                    }
                }
            } catch (\Throwable $e) {
                report($e);

                $message = config('app.debug')
                    ? $e->getMessage()
                    : 'Une erreur est survenue lors de la génération de la réponse.';

                echo "event: error\n";
                echo 'data: '.json_encode(['message' => $message], JSON_UNESCAPED_UNICODE)."\n\n";
                $this->flushSse();
            } finally {
                echo "data: [DONE]\n\n";
                $this->flushSse();
            }
        }, 200, $this->sseHeaders());
    }

    /**
     * Stream a single error frame (nothing relevant to send to the model).
     */
    private function streamError(string $message): StreamedResponse
    {
        return response()->stream(function () use ($message) {
            echo "event: error\n";
            echo 'data: '.json_encode(['message' => $message], JSON_UNESCAPED_UNICODE)."\n\n";
            $this->flushSse();
            echo "data: [DONE]\n\n";
            $this->flushSse();
        }, 200, $this->sseHeaders());
    }

    /**
     * Flush the current SSE frame to the client.
     */
    private function flushSse(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * SSE response headers (disable proxy/browser buffering).
     *
     * @return array<string, string>
     */
    private function sseHeaders(): array
    {
        return [
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Content-Type' => 'text/event-stream',
            'Connection' => 'keep-alive',
        ];
    }
}
