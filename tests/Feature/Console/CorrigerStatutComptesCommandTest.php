<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

/** Un compte « historique » : créé avant que l'inscription renseigne le statut. */
function compteSansStatut(string $email): User
{
    return User::factory()->create(['email' => $email, 'status' => null]);
}

it('n\'émet aucun appel réseau sans --execute', function () {
    Http::fake();
    compteSansStatut('ancien@example.test');

    $this->artisan('mibeko:corriger-statut-comptes', [
        '--connection' => 'pgsql',
        '--rythme' => 0,
    ])->assertSuccessful();

    Http::assertNothingSent();
    expect(User::whereNull('status')->count())->toBe(1);
});

it('refuse d\'exécuter sans jeton dans le shell', function () {
    putenv('MIBEKO_API_TOKEN');
    compteSansStatut('ancien@example.test');

    $this->artisan('mibeko:corriger-statut-comptes', [
        '--connection' => 'pgsql',
        '--rythme' => 0,
        '--execute' => true,
    ])->assertFailed();
});

it('appelle PATCH /admin/users/{id} pour chaque compte sans statut', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    $premier = compteSansStatut('ancien@example.test');
    $second = compteSansStatut('autre@example.test');
    User::factory()->create(['email' => 'deja@example.test']); // déjà `active`

    $this->artisan('mibeko:corriger-statut-comptes', [
        '--connection' => 'pgsql',
        '--rythme' => 0,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(2);
    foreach ([$premier, $second] as $compte) {
        Http::assertSent(fn ($requete) => $requete->method() === 'PATCH'
            && str_contains($requete->url(), "/admin/users/{$compte->id}")
            && $requete['status'] === 'active');
    }

    putenv('MIBEKO_API_TOKEN');
});

it('borne le lot pilote à --limit comptes', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['success' => true], 200));

    compteSansStatut('un@example.test');
    compteSansStatut('deux@example.test');
    compteSansStatut('trois@example.test');

    $this->artisan('mibeko:corriger-statut-comptes', [
        '--connection' => 'pgsql',
        '--rythme' => 0,
        '--limit' => 1,
        '--execute' => true,
    ])->assertSuccessful();

    Http::assertSentCount(1);

    putenv('MIBEKO_API_TOKEN');
});

it('signale les comptes que l\'API refuse au lieu de les compter comme corrigés', function () {
    putenv('MIBEKO_API_TOKEN=jeton-de-test');
    Http::fake(fn () => Http::response(['message' => 'Interdit'], 403));

    compteSansStatut('ancien@example.test');

    $this->artisan('mibeko:corriger-statut-comptes', [
        '--connection' => 'pgsql',
        '--rythme' => 0,
        '--execute' => true,
    ])->assertFailed();

    expect(User::whereNull('status')->count())->toBe(1);

    putenv('MIBEKO_API_TOKEN');
});

it('rejette un statut hors du référentiel', function () {
    $this->artisan('mibeko:corriger-statut-comptes', [
        '--connection' => 'pgsql',
        '--statut' => 'zombie',
    ])->assertFailed();
});
