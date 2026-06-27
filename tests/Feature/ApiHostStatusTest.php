<?php

/**
 * L'hôte api.mibeko.fr est une surface « machine » : sa racine sert une page de
 * statut (pas de vitrine ni d'authentification), tout est marqué noindex, et la
 * documentation API est protégée. Ces tests verrouillent ce contrat.
 */
it('serves a machine status page at the API root, not a marketing site', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $response->assertSee('opérationnel', false);
    // Aucune vitrine ni parcours d'authentification ne doit vivre sur l'hôte API.
    $response->assertDontSee('register');
    $response->assertDontSee('Inscription');
});

it('returns a JSON status payload to API clients', function () {
    $response = $this->getJson('/');

    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
            'version' => 'v1',
        ])
        ->assertJsonStructure(['service', 'documentation', 'application', 'website', 'health']);

    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('marks every API-host response as noindex', function () {
    $this->getJson('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('applies hardening security headers on the API host', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
});

it('protects the API documentation with basic auth when credentials are configured', function () {
    config(['docs.username' => 'dev', 'docs.password' => 'secret']);

    $this->get('/docs/api')->assertUnauthorized();

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('dev:secret'),
    ])->get('/docs/api')->assertOk();
});
