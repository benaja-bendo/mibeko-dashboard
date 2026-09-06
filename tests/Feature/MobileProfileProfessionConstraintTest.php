<?php

use App\Models\MobileProfile;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * mibeko-dashboard#98 : la contrainte SQL est le vrai garde-fou — la
 * validation de `UpdateProfileRequest` protège la route HTTP, mais rien
 * n'empêchait jusqu'ici une écriture directe (import, commande, futur
 * appelant) d'introduire une nouvelle graphie hors de la liste fermée.
 */
it('accepte les quatre valeurs de la liste fermée', function (string $profession) {
    $profile = MobileProfile::create(['user_id' => User::factory()->create()->id, 'profession' => $profession]);

    expect($profile->fresh()->profession)->toBe($profession);
})->with(['Citoyen', 'Étudiant', 'Professionnel du droit', 'Autre']);

it('accepte l\'absence de réponse', function () {
    $profile = MobileProfile::create(['user_id' => User::factory()->create()->id, 'profession' => null]);

    expect($profile->fresh()->profession)->toBeNull();
});

it('refuse une valeur hors de la liste fermée au niveau de la base', function () {
    MobileProfile::create(['user_id' => User::factory()->create()->id, 'profession' => 'Avocat']);
})->throws(QueryException::class);
