<?php

namespace App\Ai\Mistral;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Mistral\MistralGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ToolChoice;

/**
 * mibeko-dashboard#257 : interdit les outils à la dernière étape d'un parcours
 * à plusieurs étapes, pour que le modèle rédige au lieu de réclamer une
 * recherche de plus.
 *
 * Dans laravel/ai v0.9.1, `TextGenerationLoop` n'exécute aucun outil à la
 * dernière étape : si le modèle en réclame encore un, la boucle s'arrête sans
 * texte sur `FinishReason::ToolCalls`, et l'assistant affiche « Réponse
 * interrompue ». Seul le gateway Bedrock lit `StepContext::isFinalStep` ;
 * Mistral recevait les outils et `tool_choice: auto` jusqu'au bout. Une
 * question large (« Comment constituer une SARL… ») faisait ainsi chercher le
 * modèle à chaque étape, 10 à 13 fois, sans jamais écrire.
 *
 * Les outils restent déclarés dans la requête : l'historique contient leurs
 * appels et leurs résultats, seul le choix du modèle change. Un rappel s'ajoute
 * aux instructions : sans lui, un modèle à qui l'outil n'a rien rendu, forcé
 * d'écrire, répondait de mémoire (constaté le 29/10/2026 sur une sonde réelle).
 */
class FinalAnswerMistralGateway extends MistralGateway
{
    public const RAPPEL_DERNIERE_ETAPE = "DERNIÈRE ÉTAPE : tu ne peux plus appeler d'outil. Rédige maintenant ta réponse finale à partir des seuls résultats d'outils déjà reçus. S'ils ne permettent pas de répondre, dis-le au lieu de répondre de mémoire.";

    /**
     * Build the request body for the current text generation step.
     */
    protected function buildStepBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        // L'étape 0 est exclue : un parcours à une seule étape garde ses outils,
        // certains agents n'en lisent que les arguments. Un agent sans outils
        // n'a rien à interdire.
        $derniereEtape = $stepContext->isFinalStep && $stepContext->stepNumber > 0 && filled($tools);

        $body = parent::buildStepBody(
            $provider,
            $model,
            $derniereEtape ? trim($instructions."\n\n".self::RAPPEL_DERNIERE_ETAPE) : $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $stepContext,
        );

        if ($derniereEtape && isset($body['tools'])) {
            $body['tool_choice'] = ToolChoice::none;
        }

        return $body;
    }
}
