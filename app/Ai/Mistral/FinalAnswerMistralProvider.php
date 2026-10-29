<?php

namespace App\Ai\Mistral;

use Laravel\Ai\Gateway\Mistral\MistralGateway;
use Laravel\Ai\Providers\MistralProvider;

/**
 * Fournisseur Mistral dont le gateway interdit les outils à la dernière étape
 * (mibeko-dashboard#257, voir {@see FinalAnswerMistralGateway}).
 *
 * Remplace le pilote `mistral` de laravel/ai (cf. AppServiceProvider) : texte,
 * embeddings et transcription partagent le même gateway, dont seule la
 * construction de la requête de texte change.
 */
class FinalAnswerMistralProvider extends MistralProvider
{
    /**
     * Get the shared Mistral gateway instance.
     */
    protected function mistralGateway(): MistralGateway
    {
        return $this->mistralGateway ??= new FinalAnswerMistralGateway($this->events);
    }
}
