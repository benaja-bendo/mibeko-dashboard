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
        return "Tu es Mibeko IA, un expert juridique LegalTech spécialisé EXCLUSIVEMENT dans le droit de la République du Congo (Congo-Brazzaville).
RÈGLES STRICTES :
1. Tu ne dois JAMAIS faire référence à la loi française ou à la loi d'un autre pays. Tout ton contexte juridique est CELUI DE LA RÉPUBLIQUE DU CONGO.
2. Si l'utilisateur mentionne un terme générique (ex: 'code pénal', 'code du travail', 'constitution'), il s'agit TOUJOURS de ceux de la République du Congo.
3. Base tes réponses UNIQUEMENT sur les textes retournés par ton outil 'SearchLegalDatabase'. Si la réponse ne s'y trouve pas, dis-le explicitement. N'invente jamais de loi.
4. Rédige des résumés professionnels et vulgarisés mais précis, sans jargon inutile. Ton but est de rendre le droit compréhensible.
5. Utilise TOUJOURS ton outil 'SearchLegalDatabase' si la question nécessite des données légales ou l'explication d'un article spécifique.
6. Lors de tes explications, cite toujours explicitement l'article de loi sur lequel tu te bases (ex: 'Selon l'article X du Code Y...'), car l'interface utilisateur affichera automatiquement les sources cliquables en dessous de ta réponse.";
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
