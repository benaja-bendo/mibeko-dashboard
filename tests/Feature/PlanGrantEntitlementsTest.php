<?php

use App\Ai\AiUserQuotaTier;
use App\Models\PlanGrant;
use App\Models\User;
use App\Services\EntitlementsResolver;
use Spatie\Permission\Models\Role;

/**
 * mibeko-dashboard#100 : un abonnement Pro vendu à la main n'avait jusqu'ici
 * aucune représentation en base — seul le rôle `user_pro` (sans échéance, sans
 * montant, sans canal) en tenait lieu. `PlanGrant` comble ce trou en lecture
 * live, sans job planifié.
 */
it('résout le plan à pro pour un octroi en cours de validité', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->create();

    $plan = app(EntitlementsResolver::class)->resolve($user);

    expect($plan['plan'])->toBe('pro');
    expect($plan['features']['export'])->toBeTrue();
});

it('ne résout plus à pro une fois l\'octroi expiré, sans redéploiement ni job', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->expired()->create();

    $plan = app(EntitlementsResolver::class)->resolve($user);

    expect($plan['plan'])->toBe('libre');
});

it('ne résout pas à pro un octroi qui n\'a pas encore commencé', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->future()->create();

    expect(app(EntitlementsResolver::class)->resolve($user)['plan'])->toBe('libre');
});

it('n\'attribue pas le plan pro d\'un octroi appartenant à un autre compte', function () {
    $autre = User::factory()->create();
    PlanGrant::factory()->for($autre)->create();

    $user = User::factory()->create();

    expect(app(EntitlementsResolver::class)->resolve($user)['plan'])->toBe('libre');
});

it('un compte libre sans octroi reste libre', function () {
    $user = User::factory()->create();

    expect(app(EntitlementsResolver::class)->resolve($user)['plan'])->toBe('libre');
});

it('le palier de quota IA passe à user_pro pour un octroi actif, sans le rôle', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->create();

    expect(AiUserQuotaTier::tierFor($user))->toBe('user_pro');
    expect(AiUserQuotaTier::resolve($user)['scope'])->toBe('day');
});

it('le palier de quota IA retombe à standard une fois l\'octroi expiré', function () {
    $user = User::factory()->create();
    PlanGrant::factory()->for($user)->expired()->create();

    expect(AiUserQuotaTier::tierFor($user))->toBe('standard');
    expect(AiUserQuotaTier::resolve($user)['scope'])->toBe('month');
});

it('le rôle admin garde la priorité sur un octroi Pro pour le palier de quota', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin'));
    PlanGrant::factory()->for($admin)->create();

    expect(AiUserQuotaTier::tierFor($admin))->toBe('admin');
});

/**
 * mibeko-dashboard#121 : la révocation coupe l'accès sans réécrire `ends_at`,
 * pour que la période contractuelle d'origine reste lisible sur le
 * justificatif même après une coupure anticipée.
 */
it('une révocation coupe l\'accès sans réécrire la période contractuelle', function () {
    $user = User::factory()->create();
    $grant = PlanGrant::factory()->for($user)->create(['ends_at' => now()->addMonth()]);
    $contractedEndsAt = $grant->ends_at;

    $grant->revoke();
    $grant->refresh();

    expect($grant->ends_at->equalTo($contractedEndsAt))->toBeTrue()
        ->and($grant->revoked_at)->not->toBeNull()
        ->and(PlanGrant::hasActive($user))->toBeFalse()
        ->and($grant->status())->toBe('revoked');
});
