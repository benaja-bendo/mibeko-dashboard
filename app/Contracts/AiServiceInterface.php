<?php

namespace App\Contracts;

interface AiServiceInterface
{
    /**
     * Génère un embedding pour un texte donné.
     *
     * @param string $text
     * @return array
     */
    public function generateEmbedding(string $text): array;

    /**
     * Génère des embeddings pour plusieurs textes (batching).
     *
     * @param array $texts
     * @return array
     */
    public function generateEmbeddings(array $texts): array;

    /**
     * Génère une réponse (complétion chat) à partir d'un prompt et d'un contexte.
     *
     * @param string $prompt
     * @param array $messages
     * @return string|null
     */
    public function generateChatCompletion(array $messages, array $options = []): ?string;
}
