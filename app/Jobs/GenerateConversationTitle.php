<?php

namespace App\Jobs;

use App\Models\AgentConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Génère un titre court et lisible pour une conversation à partir du premier
 * message, en tâche de fond (un appel à un modèle léger). Le titre tronqué
 * posé à la création sert d'attente : il est remplacé dès que le job aboutit.
 */
class GenerateConversationTitle implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $conversationId,
        public string $firstMessage,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $conversation = AgentConversation::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        try {
            $response = agent(
                instructions: 'Génère un titre court (3 à 5 mots) résumant le sujet du message, sans guillemets ni ponctuation finale. Réponds uniquement par le titre, dans la langue du message.',
            )->prompt(Str::limit($this->firstMessage, 500));

            $title = trim(Str::limit(trim((string) $response->text), 100, ''));

            if ($title !== '') {
                $conversation->update(['title' => $title]);
            }
        } catch (Throwable $e) {
            // Échec non bloquant : on conserve le titre tronqué par défaut.
            report($e);
        }
    }
}
