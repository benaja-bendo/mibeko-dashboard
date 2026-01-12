<?php

namespace App\Services\Ai;

use App\Contracts\AiServiceInterface;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;

class OpenAiService implements AiServiceInterface
{
    /**
     * @inheritDoc
     */
    public function generateEmbedding(string $text): array
    {
        try {
            $response = OpenAI::embeddings()->create([
                'model' => config('ai.providers.openai.embedding_model', 'text-embedding-3-small'),
                'input' => $text,
            ]);
            return $response->embeddings[0]->embedding;
        } catch (\Exception $e) {
            Log::error('OpenAI Embedding Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function generateEmbeddings(array $texts): array
    {
        try {
            $response = OpenAI::embeddings()->create([
                'model' => config('ai.providers.openai.embedding_model', 'text-embedding-3-small'),
                'input' => $texts,
            ]);

            return array_map(fn($emb) => $emb->embedding, $response->embeddings);
        } catch (\Exception $e) {
            Log::error('OpenAI Batch Embedding Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function generateChatCompletion(array $messages, array $options = []): ?string
    {
        try {
            $response = OpenAI::chat()->create(array_merge([
                'model' => config('ai.providers.openai.chat_model', 'gpt-4o-mini'),
                'messages' => $messages,
                'temperature' => 0.3,
            ], $options));

            return $response->choices[0]->message->content;
        } catch (\Exception $e) {
            Log::error('OpenAI Chat Error: ' . $e->getMessage());
            return null;
        }
    }
}
