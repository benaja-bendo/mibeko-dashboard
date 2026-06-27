<?php

/**
 * La recherche du fonds est une surface publique (site vitrine, sans compte) :
 * elle doit rester accessible sans authentification, valider son entrée, et
 * disposer de son propre quota (throttle:search_public) distinct de l'API.
 */
it('exposes the library search publicly without authentication', function () {
    // Sans `q`, la validation répond 422 — preuve que la route est atteinte
    // publiquement (pas de 401) avant même de toucher la base.
    $this->getJson('/api/v1/library/search')->assertStatus(422);
});

it('rejects a too-short query', function () {
    $this->getJson('/api/v1/library/search?q=a')->assertStatus(422);
});

it('applies the dedicated public search rate limiter', function () {
    // Le quota de test (5/min) borne l'endpoint : la 6e requête est rejetée 429.
    foreach (range(1, 5) as $i) {
        $this->getJson('/api/v1/library/search')->assertStatus(422);
    }

    $this->getJson('/api/v1/library/search')->assertStatus(429);
});
