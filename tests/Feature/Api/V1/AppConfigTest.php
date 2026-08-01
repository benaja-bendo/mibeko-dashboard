<?php

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;

/**
 * Les valeurs viennent de l'environnement (config/mobile.php lit env()) :
 * sans ce figeage, un `.env` local qui exerce le levier force-update
 * (MOBILE_LATEST_VERSION=…) ferait échouer la suite.
 */
beforeEach(function () {
    config()->set('mobile.min_supported_version', '1.0');
    config()->set('mobile.latest_version', '1.1.0');
    config()->set('mobile.update_message', null);
    config()->set('mobile.store_urls.android', 'https://play.google.com/store/apps/details?id=cg.mibeko.app');
    config()->set('mobile.store_urls.ios', 'https://apps.apple.com/app/id6768865781');
    config()->set('mobile.release_webhook_secret', 'test-release-secret');
});

it('expose le contrat force-update sans authentification', function () {
    $response = $this->getJson('/api/v1/app-config');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'min_supported_version',
            'latest_version',
            'message',
            'store_urls' => ['android', 'ios'],
        ]);

    $payload = $response->json();

    expect($payload['min_supported_version'])->toBeString()->not->toBe('')
        ->and($payload['latest_version'])->toBeString()->not->toBe('')
        // Pas de message de mise à jour par défaut.
        ->and($payload['message'])->toBeNull()
        ->and($payload['store_urls']['android'])->toStartWith('https://play.google.com/store/apps/details?id=cg.mibeko.app')
        ->and($payload['store_urls']['ios'])->toBe('https://apps.apple.com/app/id6768865781');
});

it('expose null plutôt qu\'une chaîne vide quand une URL de store est vidée', function () {
    config()->set('mobile.store_urls.ios', '');

    $this->getJson('/api/v1/app-config')
        ->assertStatus(200)
        ->assertJsonPath('store_urls.ios', null);
});

it('reflète la configuration mobile et renvoie un Cache-Control public', function () {
    config()->set('mobile.min_supported_version', '1.1.0');
    config()->set('mobile.latest_version', '1.2.0');
    config()->set('mobile.update_message', 'Cette version n\'est plus supportée.');
    config()->set('mobile.store_urls.ios', 'https://apps.apple.com/app/id0000000000');

    $this->getJson('/api/v1/app-config')
        ->assertStatus(200)
        ->assertHeader('Cache-Control', 'max-age=300, public')
        ->assertJsonPath('min_supported_version', '1.1.0')
        ->assertJsonPath('latest_version', '1.2.0')
        ->assertJsonPath('message', 'Cette version n\'est plus supportée.')
        ->assertJsonPath('store_urls.ios', 'https://apps.apple.com/app/id0000000000');
});

it('met la réponse en cache serveur (~10 min)', function () {
    // Premier appel : construit et stocke le payload.
    $this->getJson('/api/v1/app-config')->assertJsonPath('latest_version', '1.1.0');

    expect(Cache::has('mobile:app-config'))->toBeTrue();

    // Un changement de config sans invalidation n'est pas visible tant que le
    // cache n'a pas expiré : c'est le compromis assumé (TTL court).
    config()->set('mobile.latest_version', '9.9.9');

    $this->getJson('/api/v1/app-config')->assertJsonPath('latest_version', '1.1.0');
});

it('refuse la mise à jour de latest_version sans secret', function () {
    $this->postJson('/api/v1/app-config/latest-version', ['version' => '1.2.0'])
        ->assertStatus(401);
});

it('refuse la mise à jour de latest_version avec un mauvais secret', function () {
    $this->postJson(
        '/api/v1/app-config/latest-version',
        ['version' => '1.2.0'],
        ['X-Mobile-Release-Secret' => 'un-mauvais-secret'],
    )->assertStatus(401);
});

it('refuse un format de version invalide', function () {
    $this->postJson(
        '/api/v1/app-config/latest-version',
        ['version' => 'not-a-version'],
        ['X-Mobile-Release-Secret' => 'test-release-secret'],
    )->assertStatus(422);
});

it('met à jour latest_version avec le bon secret et invalide le cache immédiatement', function () {
    // 3 requêtes dans un seul test : throttle:api désactivé (limité à 2/min en
    // environnement de test, cf. AppServiceProvider::boot).
    $this->withoutMiddleware(ThrottleRequests::class);

    // Peuple le cache avec l'ancienne valeur, comme le ferait un vrai démarrage d'app.
    $this->getJson('/api/v1/app-config')->assertJsonPath('latest_version', '1.1.0');

    $this->postJson(
        '/api/v1/app-config/latest-version',
        ['version' => '1.2.0'],
        ['X-Mobile-Release-Secret' => 'test-release-secret'],
    )->assertStatus(200)->assertJsonPath('latest_version', '1.2.0');

    // Pas d'attente de TTL : l'écriture force l'invalidation du cache serveur.
    $this->getJson('/api/v1/app-config')->assertJsonPath('latest_version', '1.2.0');
});
