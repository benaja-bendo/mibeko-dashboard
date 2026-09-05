<?php

use App\Http\Controllers\Api\V1\LibraryAiController;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * mibeko-dashboard#91 : `resolveKnownProviderModel()` est le seul point qui
 * décide si une ligne d'erreur `ai_usage_logs` peut porter un fournisseur/
 * modèle déjà connus. Testé directement (réflexion) plutôt qu'en bout en bout
 * HTTP : `AnonymousAgent::fake()` calcule toute la réponse AVANT d'émettre le
 * moindre événement, donc il ne peut pas simuler un `StreamStart` suivi d'une
 * exception — la seule façon fiable de couvrir ce cas est d'appeler la
 * méthode d'extraction avec un tableau d'événements construit à la main.
 */
function resolveKnownProviderModel(array $events): array
{
    $controller = app(LibraryAiController::class);
    $method = new ReflectionMethod($controller, 'resolveKnownProviderModel');

    return $method->invoke($controller, $events);
}

it('retrouve le fournisseur et le modèle depuis un StreamStart déjà reçu', function () {
    $events = [
        new StreamStart('evt-1', 'mistral', 'mistral-large-latest', time()),
        new TextDelta('evt-2', 'msg-1', 'Bonjour', time()),
    ];

    [$provider, $model] = resolveKnownProviderModel($events);

    expect($provider)->toBe('mistral');
    expect($model)->toBe('mistral-large-latest');
});

it('laisse fournisseur et modèle à null quand aucun StreamStart n\'a été reçu', function () {
    [$provider, $model] = resolveKnownProviderModel([]);

    expect($provider)->toBeNull();
    expect($model)->toBeNull();
});
