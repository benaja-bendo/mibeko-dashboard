<?php

namespace App\Services\Ai;

use App\Contracts\AiServiceInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class MistralAiService implements AiServiceInterface
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.mistral.ai/v1';

    public function __construct()
    {
        $this->apiKey = config('ai.providers.mistral.api_key', '');
    }

    /**
     * @inheritDoc
     */
    public function generateEmbedding(string $text): array
    {
        // Exemple d'implémentation via HTTP client
        try {
            $response = Http::withToken($this->apiKey)
                ->post("{$this->baseUrl}/embeddings", [
                    'model' => config('ai.providers.mistral.embedding_model', 'mistral-embed'),
                    'input' => [$text],
                ]);

            if ($response->failed()) {
                throw new \Exception("Mistral API Error: " . $response->body());
            }

            return $response->json('data.0.embedding');
        } catch (\Exception $e) {
            Log::error('Mistral Embedding Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function generateEmbeddings(array $texts): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->post("{$this->baseUrl}/embeddings", [
                    'model' => config('ai.providers.mistral.embedding_model', 'mistral-embed'),
                    'input' => $texts,
                ]);

            if ($response->failed()) {
                throw new \Exception("Mistral API Error: " . $response->body());
            }

            return array_map(fn($item) => $item['embedding'], $response->json('data'));
        } catch (\Exception $e) {
            Log::error('Mistral Batch Embedding Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function generateChatCompletion(array $messages, array $options = []): ?string
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->post("{$this->baseUrl}/chat/completions", array_merge([
                    'model' => config('ai.providers.mistral.chat_model', 'mistral-tiny'),
                    'messages' => $messages,
                ], $options));

            if ($response->failed()) {
                throw new \Exception("Mistral API Error: " . $response->body());
            }

            return $response->json('choices.0.message.content');
        } catch (\Exception $e) {
            Log::error('Mistral Chat Error: ' . $e->getMessage());
            return null;
        }
    }
}
