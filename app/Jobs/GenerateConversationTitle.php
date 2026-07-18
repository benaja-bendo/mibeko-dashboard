<?php

namespace App\Jobs;

use App\Ai\Agents\ConversationTitler;
use App\Models\AgentConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Génère un titre court et lisible pour une conversation à partir du premier
 * message, en tâche de fond (un appel à un modèle léger). Le titre tronqué
 * posé à la création sert d'attente : il est remplacé dès que le job aboutit.
 */
class GenerateConversationTitle implements ShouldQueue
{
    use Queueable;

    /** Deux tentatives : le titre est cosmétique, inutile d'insister. */
    public int $tries = 2;

    /** Délai (secondes) avant la nouvelle tentative. */
    public array $backoff = [15];

    public function __construct(
        public string $conversationId,
        public string $firstMessage,
    ) {}

    /**
     * Execute the job.
     *
     * Idempotent : le job ne fait que (re)poser le titre d'une conversation
     * existante. On laisse donc l'exception d'un appel LLM raté remonter pour
     * que la file honore réellement `$tries`/`$backoff` (au lieu de l'avaler,
     * ce qui rendait le retry fictif). L'échec final est traité par {@see failed()}.
     */
    public function handle(): void
    {
        $conversation = AgentConversation::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $response = (new ConversationTitler)->prompt(Str::limit($this->firstMessage, 500));

        $title = trim(Str::limit(trim((string) $response->text), 100, ''));

        if ($title !== '') {
            $conversation->update(['title' => $title]);
        }
    }

    /**
     * Toutes les tentatives ont échoué : le titre est cosmétique, on ne bloque
     * rien. La conversation conserve son titre tronqué par défaut ; on trace
     * seulement l'échec pour le monitoring.
     */
    public function failed(Throwable $e): void
    {
        report($e);
    }
}
