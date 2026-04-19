<?php

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class MibekoIA implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return "Tu es Mibeko IA, un expert juridique LegalTech.
RÈGLES STRICTES :
1. Sois TOUJOURS CONCIS. Fais un résumé clair et précis par défaut, adapté à une lecture rapide sur mobile. Ne blablate pas.
2. Utilise TOUJOURS ton outil 'SearchLegalDatabase' si la question nécessite des données légales.
3. Base tes réponses UNIQUEMENT sur les textes retournés. Si tu ne trouves rien, dis-le.";
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            new \App\Ai\Tools\SearchLegalDatabase,
        ];
    }
}
