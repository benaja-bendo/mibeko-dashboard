<?php

namespace App\Ai;

use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

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
 * Les deltas SSE sont normalisés ligne par ligne, sans précharger le flux (#105).
 */
class NormalizesContentBlockResponses
{
    public function __invoke(ResponseInterface $response): ResponseInterface
    {
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'text/event-stream')) {
            $source = $response->getBody();

            // Lecture paresseuse : ne jamais attendre la réponse complète avant
            // de transmettre le premier fragment au lecteur SSE du SDK.
            return $response->withoutHeader('Content-Length')->withBody(new PumpStream(function () use ($source): string|false {
                if ($source->eof()) {
                    return false;
                }

                $line = Utils::readLine($source);
                if (! str_starts_with($line, 'data:')) {
                    return $line;
                }

                $data = json_decode(trim(substr($line, 5)), true);
                if (! is_array($data) || ! isset($data['choices']) || ! is_array($data['choices'])) {
                    return $line;
                }

                $normalized = $this->normalizeChoices($data, 'delta');

                return $normalized === $data ? $line : 'data: '.json_encode($normalized, JSON_THROW_ON_ERROR)."\n";
            }));
        }

        if (! str_contains($contentType, 'json')) {
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

        $normalized = $this->normalizeChoices($data, 'message');

        return $normalized === $data ? $response : $response->withoutHeader('Content-Length')
            ->withBody(Utils::streamFor(json_encode($normalized, JSON_THROW_ON_ERROR)));
    }

    private function normalizeChoices(array $data, string $field): array
    {
        foreach ($data['choices'] as &$choice) {
            $content = $choice[$field]['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }

            $text = '';
            foreach ($content as $block) {
                if (is_string($block)) {
                    $text .= $block;
                } elseif (is_array($block) && ($block['type'] ?? 'text') === 'text') {
                    if (! is_string($block['text'] ?? null)) {
                        throw new UnexpectedValueException('Le fournisseur IA a renvoyé un bloc de texte invalide.');
                    }
                    $text .= $block['text'];
                }
            }
            $choice[$field]['content'] = $text;
        }

        return $data;
    }
}
