<?php

namespace App\Ai\Storage;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Store de conversation qui allège l'historique rejoué au modèle.
 *
 * Le store par défaut rejoue, à chaque tour, l'intégralité des `tool_results`
 * des tours précédents — c'est-à-dire le texte complet des articles trouvés,
 * qui peut peser des centaines de Ko par recherche. Sur une conversation qui
 * dure, le contexte (et donc le coût et la latence) explose, jusqu'au
 * dépassement de la fenêtre de contexte.
 *
 * On ne conserve in extenso que les résultats du DERNIER tour d'outil ; ceux
 * des tours antérieurs sont réduits à un stub. Le message d'outil reste présent
 * pour chaque appel (le couple tool_use/tool_result attendu par l'API du modèle
 * n'est jamais rompu) : seul le volume tombe. L'agent re-cherche de toute façon
 * à chaque tour, donc rien d'utile n'est perdu.
 */
class CompactingConversationStore extends DatabaseConversationStore
{
    /**
     * Texte de remplacement des extraits des tours précédents.
     */
    protected const OMITTED_TOOL_RESULT = '[Extraits d\'une recherche d\'un tour précédent retirés du contexte pour limiter les tokens. Relance SearchLegalDatabase si tu as besoin du texte exact.]';

    /**
     * Get the latest messages for the given conversation.
     *
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $messages = parent::getLatestConversationMessages($conversationId, $limit);

        // Index du dernier message d'outil de la fenêtre : ses résultats restent
        // complets, tous les précédents sont réduits à un stub.
        $lastToolResultIndex = null;
        foreach ($messages as $index => $message) {
            if ($message instanceof ToolResultMessage) {
                $lastToolResultIndex = $index;
            }
        }

        if ($lastToolResultIndex === null) {
            return $messages;
        }

        foreach ($messages as $index => $message) {
            if ($index === $lastToolResultIndex || ! $message instanceof ToolResultMessage) {
                continue;
            }

            $message->toolResults->each(function ($toolResult): void {
                $toolResult->result = self::OMITTED_TOOL_RESULT;
            });
        }

        return $messages;
    }
}
