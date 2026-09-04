<?php

namespace App\Ai;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

/**
 * mibeko-dashboard#80 : les fournisseurs au format Chat Completions (Mistral,
 * OpenAI compris) peuvent renvoyer `message.content` sous forme de blocs
 * (`[{"type":"text","text":"…"}]`) plutôt qu'une simple chaîne — les deux
 * formes sont valides côté API. `laravel/ai` v0.9.1 ne lit que la chaîne
 * (`ParsesTextResponses` des gateways Mistral et OpenAiCompatible) et fait
 * planter `StepResponse::__construct()` sur un tableau : le tour entier
 * échoue après que le modèle a pourtant produit la bonne réponse.
 *
 * Aplatit les blocs en texte au niveau HTTP, avant que le paquet ne les
 * voie — pas de correctif dans vendor/, pas de montée de version pour un
 * seul défaut de parsing. Enregistré comme middleware réponse global
 * (Http::globalResponseMiddleware) car cette forme de réponse touche tout
 * fournisseur compatible OpenAI, y compris la chaîne de secours #77.
 */
class NormalizesContentBlockResponses
{
    public function __invoke(ResponseInterface $response): ResponseInterface
    {
        if (! str_contains($response->getHeaderLine('Content-Type'), 'json')) {
            return $response;
        }

        $body = (string) $response->getBody();

        if ($body === '') {
            return $response;
        }

        $data = json_decode($body, true);

        if (! is_array($data) || ! isset($data['choices']) || ! is_array($data['choices'])) {
            return $response;
        }

        $changed = false;

        foreach ($data['choices'] as &$choice) {
            $content = $choice['message']['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            $choice['message']['content'] = collect($content)
                ->map(fn ($block) => is_array($block) ? ($block['text'] ?? '') : (string) $block)
                ->implode('');

            $changed = true;
        }
        unset($choice);

        if (! $changed) {
            return $response;
        }

        return $response->withBody(Utils::streamFor(json_encode($data)));
    }
}
