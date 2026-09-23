<?php

use App\Models\Article;
use App\Models\DocumentType;
use App\Models\LegalDocument;
use App\Models\OfficialJournal;
use App\Models\SearchLog;
use Laravel\Ai\Embeddings;

/**
 * Entrées des routes publiques de recherche et de catalogue, durcies après
 * la vague de sondes XSS du 22-23/09/2026 (`'><asdf alt="">-f3f3` et ses
 * variantes encodées, reçues sur mibeko.fr/textes). Aucune injection n'a
 * abouti ; l'audit a en revanche trouvé une 500 déclenchable par n'importe
 * qui, une taille de page non bornée par le bas et une longueur de requête
 * sans plafond.
 */
beforeEach(function () {
    Embeddings::fake();
});

it('rejette un q tableau sur legal-documents/search au lieu de lever une 500', function () {
    $this->getJson('/api/v1/legal-documents/search?q[]=x')->assertStatus(422);

    expect(SearchLog::count())->toBe(0);
});

it('plafonne la longueur de q à 255 caractères sur chaque recherche publique', function (string $endpoint) {
    $this->getJson($endpoint.'?q='.str_repeat('a', 256))
        ->assertStatus(422)
        ->assertJsonValidationErrors('q');
})->with([
    'library/search' => '/api/v1/library/search',
    'search' => '/api/v1/search',
    'articles/search' => '/api/v1/articles/search',
    'legal-documents/search' => '/api/v1/legal-documents/search',
]);

it('accepte une requête de 255 caractères', function () {
    $this->getJson('/api/v1/library/search?q='.str_repeat('a', 255))->assertOk();
    $this->getJson('/api/v1/legal-documents/search?q='.str_repeat('a', 255))->assertOk();
});

it('ne rend jamais toute la table quand per_page est négatif', function (string $endpoint) {
    $type = DocumentType::create(['code' => 'LOI', 'nom' => 'Loi']);
    // `published()` exige au moins un article : sans lui, rien ne s'affiche.
    LegalDocument::factory()->count(25)->has(Article::factory(), 'articles')->create([
        'type_code' => $type->code,
        'titre_officiel' => 'Loi relative au travail',
    ]);

    $response = $this->getJson($endpoint.'per_page=-1')->assertOk();

    // Le query builder ignore une LIMIT négative : avant le correctif, les
    // 25 documents revenaient d'un bloc sur une « page ».
    expect($response->json('data'))->toHaveCount(20);
    expect($response->json('pagination.per_page'))->toBe(20);
})->with([
    'legal-documents' => '/api/v1/legal-documents?',
    'legal-documents/search' => '/api/v1/legal-documents/search?q=travail&',
]);

it('garde le plafond de 100 par page', function () {
    $response = $this->getJson('/api/v1/legal-documents?per_page=5000')->assertOk();

    expect($response->json('pagination.per_page'))->toBe(100);
});

it('borne aussi per_page sur la liste publique des journaux officiels', function () {
    OfficialJournal::factory()->count(20)->create();

    $response = $this->getJson('/api/v1/official-journals?per_page=-1')->assertOk();

    expect($response->json('data'))->toHaveCount(15);
});
