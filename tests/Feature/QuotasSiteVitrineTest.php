<?php

use App\Models\Article;
use App\Models\DocumentType;
use App\Models\LegalDocument;

/**
 * Quotas vus depuis le site vitrine (mibeko-dashboard#159). Le serveur SSR de
 * mibeko.fr appelle l'API depuis une seule IP : les lectures publiques du
 * fonds doivent échapper au quota générique par IP, et l'IP du visiteur,
 * relayée en `X-Forwarded-For` depuis un réseau privé, doit faire foi — sans
 * qu'un client public puisse en forger une.
 *
 * En test : `throttle:api` = 2/min par IP, `corpus_read` = 60/min par identité.
 */
beforeEach(function () {
    DocumentType::firstOrCreate(['code' => 'CODE'], ['nom' => 'Code']);
});

it('sert les lectures publiques du fonds au-delà du quota générique par IP', function () {
    $document = LegalDocument::factory()->create([
        'type_code' => 'CODE',
        'slug' => 'code-de-la-famille',
        'curation_status' => 'published',
    ]);
    Article::factory()->create(['document_id' => $document->id, 'numero_article' => '1', 'ordre_affichage' => 1]);

    // Trois appels d'une même IP : sous `throttle:api` (2/min en test) le
    // troisième répondrait 429 — c'était le plafond du site entier.
    foreach ([1, 2, 3] as $appel) {
        $this->getJson('/api/v1/legal-documents/slug/code-de-la-famille')->assertOk();
    }

    foreach ([1, 2, 3] as $appel) {
        $this->getJson('/api/v1/document-types')->assertOk();
    }
});

it('clé les quotas par visiteur quand le site relaie son IP depuis un réseau privé', function () {
    // `institutions` reste sous `throttle:api` : route témoin.
    $depuisLeSite = fn (string $visiteur) => $this
        ->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
        ->withHeaders(['X-Forwarded-For' => $visiteur])
        ->getJson('/api/v1/institutions');

    $depuisLeSite('203.0.113.7')->assertOk();
    $depuisLeSite('203.0.113.7')->assertOk();
    $depuisLeSite('203.0.113.7')->assertStatus(429);
    // Un autre visiteur, relayé par le même conteneur, n'est pas pénalisé.
    $depuisLeSite('203.0.113.8')->assertOk();
});

it('ignore un X-Forwarded-For forgé depuis une IP publique', function () {
    $depuisInternet = fn (string $forge) => $this
        ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->withHeaders(['X-Forwarded-For' => $forge])
        ->getJson('/api/v1/institutions');

    // Trois IP forgées différentes, une seule IP réelle : le quota s'applique.
    $depuisInternet('203.0.113.1')->assertOk();
    $depuisInternet('203.0.113.2')->assertOk();
    $depuisInternet('203.0.113.3')->assertStatus(429);
});
