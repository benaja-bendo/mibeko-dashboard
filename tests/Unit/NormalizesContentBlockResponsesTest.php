<?php

use App\Ai\NormalizesContentBlockResponses;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

/**
 * mibeko-dashboard#80 : un fournisseur compatible OpenAI (Mistral compris) peut
 * renvoyer `message.content` en blocs (`[{"type":"text","text":"…"}]`) au lieu
 * d'une chaîne — les deux formes sont valides côté API, mais laravel/ai v0.9.1
 * ne lit que la chaîne et fait planter StepResponse::__construct(). Ce
 * middleware aplatit les blocs avant que le paquet ne les voie.
 */
beforeEach(function () {
    $this->middleware = new NormalizesContentBlockResponses;
});

function reponseJson(array $donnees): GuzzleResponse
{
    return new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode($donnees));
}

it('aplatit un contenu en blocs de texte en une chaîne unique', function () {
    // Forme réellement observée le 04/09/2026 (mibeko-dashboard#80) sur la
    // réponse finale d'un tour à plusieurs appels d'outil.
    $reponse = reponseJson([
        'choices' => [[
            'message' => [
                'content' => [
                    ['type' => 'text', 'text' => "L'Acte uniforme relatif au droit commercial général institue le "],
                    ['type' => 'text', 'text' => 'Registre du Commerce et du Crédit Mobilier (RCCM).'],
                ],
            ],
        ]],
    ]);

    $resultat = json_decode((string) ($this->middleware)($reponse)->getBody(), true);

    expect($resultat['choices'][0]['message']['content'])
        ->toBe("L'Acte uniforme relatif au droit commercial général institue le Registre du Commerce et du Crédit Mobilier (RCCM).");
});

it('laisse un contenu déjà en chaîne strictement inchangé', function () {
    $reponse = reponseJson([
        'choices' => [['message' => ['content' => 'Réponse déjà au bon format.']]],
    ]);

    $corpsOriginal = (string) $reponse->getBody();
    $resultat = ($this->middleware)($reponse);

    expect((string) $resultat->getBody())->toBe($corpsOriginal);
});

it('traite chaque choix indépendamment quand plusieurs sont renvoyés', function () {
    $reponse = reponseJson([
        'choices' => [
            ['message' => ['content' => [['type' => 'text', 'text' => 'Premier.']]]],
            ['message' => ['content' => 'Second, déjà en chaîne.']],
        ],
    ]);

    $resultat = json_decode((string) ($this->middleware)($reponse)->getBody(), true);

    expect($resultat['choices'][0]['message']['content'])->toBe('Premier.')
        ->and($resultat['choices'][1]['message']['content'])->toBe('Second, déjà en chaîne.');
});

it('ignore une réponse d\'erreur sans provoquer d\'exception', function () {
    $reponse = reponseJson(['object' => 'error', 'message' => 'rate limit exceeded']);

    $resultat = ($this->middleware)($reponse);

    expect(json_decode((string) $resultat->getBody(), true))
        ->toBe(['object' => 'error', 'message' => 'rate limit exceeded']);
});

it('ignore un corps vide ou non JSON sans lever d\'exception', function () {
    $vide = new GuzzleResponse(200, ['Content-Type' => 'application/json'], '');
    $nonJson = new GuzzleResponse(200, ['Content-Type' => 'text/plain'], 'ok');

    expect((string) ($this->middleware)($vide)->getBody())->toBe('')
        ->and((string) ($this->middleware)($nonJson)->getBody())->toBe('ok');
});

it('préserve les appels d\'outils sur le même message', function () {
    $reponse = reponseJson([
        'choices' => [[
            'message' => [
                'content' => [['type' => 'text', 'text' => 'Voici le résultat.']],
                'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'SearchLegalDatabase', 'arguments' => '{}']]],
            ],
        ]],
    ]);

    $resultat = json_decode((string) ($this->middleware)($reponse)->getBody(), true);

    expect($resultat['choices'][0]['message']['content'])->toBe('Voici le résultat.')
        ->and($resultat['choices'][0]['message']['tool_calls'][0]['id'])->toBe('call_1');
});
