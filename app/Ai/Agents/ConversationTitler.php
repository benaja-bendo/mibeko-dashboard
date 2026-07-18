<?php

namespace App\Ai\Agents;

use App\Jobs\GenerateConversationTitle;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Génère un titre court (3-5 mots) pour une conversation à partir de son premier
 * message. Purement cosmétique → tourne sur le modèle le moins cher du
 * fournisseur ({@see UseCheapestModel}), à l'image de la génération de titre
 * intégrée au package (mode synchrone). Utilisé par le job
 * {@see GenerateConversationTitle} pour les tours streamés/en cache.
 */
#[UseCheapestModel]
class ConversationTitler implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Génère un titre court (3 à 5 mots) résumant le sujet du message, sans guillemets '
            .'ni ponctuation finale. Réponds uniquement par le titre, dans la langue du message.';
    }
}
