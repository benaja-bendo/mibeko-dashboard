<?php

use App\Models\Article;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * mibeko-dashboard#137 — jeu de contrat commun pour les équipes web/mobile,
 * même mécanisme que `tests/Feature/OnboardingContractFixturesTest.php`
 * (#136) : écrit une seule fois à partir d'une réponse réelle déjà vérifiée
 * correcte par `ProductEventControllerTest.php`, puis comparé à l'identique
 * à chaque exécution suivante.
 */
function assertMatchesProductEventFixture(string $name, array $actual): void
{
    $path = base_path("tests/Fixtures/ProductEvents/{$name}.json");

    if (! file_exists($path)) {
        file_put_contents(
            $path,
            json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    expect($actual)->toEqual(json_decode(file_get_contents($path), true));
}

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    Carbon::setTestNow(Carbon::parse('2026-01-01T00:00:00+00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('correspond au contrat pour un enregistrement réussi', function () {
    $user = User::factory()->create();
    $article = Article::factory()->create();

    $data = $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'search_useful',
        'surface' => 'web',
        'reference_id' => $article->id,
        'client_event_id' => 'fixture-evt-1',
    ])->assertStatus(201)->json();

    // L'identifiant de l'événement et de l'article varient à chaque
    // exécution (uuid) : remplacés par un jeton stable avant comparaison,
    // le reste de la forme doit rester strictement identique.
    $data['data']['id'] = '<uuid>';

    assertMatchesProductEventFixture('store-success', $data);
});

it('correspond au contrat pour une référence invalide', function () {
    $user = User::factory()->create();

    $data = $this->actingAs($user)->postJson('/api/v1/product-events', [
        'event_type' => 'source_opened_after_answer',
        'surface' => 'mobile',
        'reference_id' => (string) Str::uuid(),
        'client_event_id' => 'fixture-evt-2',
    ])->assertStatus(422)->json();

    assertMatchesProductEventFixture('store-invalid-reference', $data);
});
