<?php

use App\Models\MobileProfile;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * mibeko-dashboard#135 : verrou anti-doublon sur `mobile_profiles.user_id`.
 * `ProfileController::update` teste `$user->mobileProfile` puis crée sinon —
 * sans contrainte DB, deux PATCH concurrents pouvaient chacun lire `null` et
 * créer chacun leur ligne. Ce test prouve la contrainte SQL elle-même, pas
 * une vraie course HTTP concurrente (impossible à simuler fiablement dans un
 * seul process Pest) — la garantie de non-duplication vient de la combinaison
 * de cette contrainte UNIQUE avec l'upsert atomique du contrôleur.
 */
it('refuse une deuxième ligne mobile_profiles pour le même user_id', function () {
    $user = User::factory()->create();
    MobileProfile::create(['user_id' => $user->id, 'profession' => 'Citoyen']);

    MobileProfile::create(['user_id' => $user->id, 'profession' => 'Étudiant']);
})->throws(QueryException::class);
