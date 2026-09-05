<?php

use App\Ai\AiUserQuotaTier;
use App\Models\AiQuotaTierSetting;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * mibeko-dashboard#95 : ordre de priorité de la LIMITE (jamais la portée,
 * fixée par le rôle) — override compte > réglage de palier en base >
 * `config('ai.quotas')`. Le limiteur de débit et `EntitlementsResolver`
 * passent tous les deux par `AiUserQuotaTier::resolve()` : le tester
 * directement couvre les deux d'un coup.
 */
beforeEach(function () {
    Role::findOrCreate('user_pro');
});

it('retombe sur config/ai.php sans réglage ni override', function () {
    $user = User::factory()->create();

    $tier = AiUserQuotaTier::resolve($user);

    expect($tier['scope'])->toBe('month');
    expect($tier['limit'])->toBe((int) config('ai.quotas.standard.per_month'));
});

it('un réglage de palier en base remplace la limite de config', function () {
    $user = User::factory()->create();
    AiQuotaTierSetting::create(['tier' => 'standard', 'limit' => 77]);

    $tier = AiUserQuotaTier::resolve($user);

    expect($tier['scope'])->toBe('month');
    expect($tier['limit'])->toBe(77);
});

it('un override posé sur le compte prime sur le réglage de palier', function () {
    $user = User::factory()->create();
    AiQuotaTierSetting::create(['tier' => 'standard', 'limit' => 77]);
    $user->settingsOrCreate()->update(['ai_quota_override_limit' => 5]);

    $tier = AiUserQuotaTier::resolve($user);

    expect($tier['scope'])->toBe('month');
    expect($tier['limit'])->toBe(5);
});

it('la portée reste fixée par le rôle même avec un override', function () {
    $user = User::factory()->create();
    $user->assignRole('user_pro');
    $user->settingsOrCreate()->update(['ai_quota_override_limit' => 5]);

    $tier = AiUserQuotaTier::resolve($user);

    expect($tier['scope'])->toBe('day');
    expect($tier['limit'])->toBe(5);
});

it('un override à zéro est bien appliqué (pas confondu avec une absence)', function () {
    $user = User::factory()->create();
    $user->settingsOrCreate()->update(['ai_quota_override_limit' => 0]);

    $tier = AiUserQuotaTier::resolve($user);

    expect($tier['limit'])->toBe(0);
});
