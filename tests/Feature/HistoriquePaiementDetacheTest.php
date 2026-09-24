<?php

use App\Models\ManualPaymentOrder;
use App\Models\PlanGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Décision du 24/09/2026 : la trace d'un paiement survit à l'effacement
 * définitif du compte (dix ans, obligations comptables), détachée de lui.
 */
it('garde le paiement, sans titulaire, quand la ligne du compte disparaît', function () {
    $compte = User::factory()->create();
    $octroi = PlanGrant::factory()->create(['user_id' => $compte->id]);
    $commande = ManualPaymentOrder::factory()->create(['user_id' => $compte->id, 'plan_grant_id' => $octroi->id]);

    // Effacement physique, comme la purge le fait (query builder).
    DB::table('users')->where('id', $compte->id)->delete();

    expect($octroi->fresh())->not->toBeNull()
        ->and($octroi->fresh()->user_id)->toBeNull()
        ->and($octroi->fresh()->amount_fcfa)->toBe($octroi->amount_fcfa)
        ->and($commande->fresh())->not->toBeNull()
        ->and($commande->fresh()->user_id)->toBeNull()
        ->and($commande->fresh()->plan_grant_id)->toBe($octroi->id);
});

it('refuse de défaire la migration tant qu\'un paiement est détaché d\'un compte', function () {
    PlanGrant::factory()->create(['user_id' => null]);
    $migration = require database_path('migrations/2026_09_24_100000_detach_payment_history_from_deleted_accounts.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'retour arrière refusé');
});
