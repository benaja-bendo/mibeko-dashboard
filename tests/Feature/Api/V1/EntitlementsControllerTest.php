<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Cashier\Subscription;
use Spatie\Permission\Models\Role;

/**
 * mibeko-dashboard#63 : point unique de vérité du droit d'usage — plan,
 * fonctionnalités, quotas, solde. Le rôle Spatie ne doit plus jamais décider
 * seul du plan (c'est précisément le défaut corrigé ici) ; ces tests créent
 * un abonnement Cashier local (aucun appel Stripe réel) pour le prouver.
 */
it('refuse un appelant non authentifié', function () {
    $this->getJson('/api/v1/me/entitlements')->assertUnauthorized();
});

it('résout le palier libre pour un compte sans abonnement ni rôle staff', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('mobile_user')); // rôle par défaut de toute inscription — n'est pas un palier

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.plan', 'libre')
        ->assertJsonPath('data.features.assistant', true)
        ->assertJsonPath('data.features.library', true)
        ->assertJsonPath('data.features.export', false)
        ->assertJsonPath('data.quotas.assistant.limit', config('ai.quotas.standard.per_month'))
        ->assertJsonPath('data.quotas.assistant.used', 0)
        ->assertJsonPath('data.quotas.assistant.resets_at', null)
        ->assertJsonPath('data.credits', null);
});

it('résout le palier pro depuis un abonnement Cashier réel, pas depuis le rôle user_pro', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user, 'user')->create();

    // Aucun rôle user_pro assigné : la preuve que le plan vient de Cashier.
    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.plan', 'pro')
        ->assertJsonPath('data.features.export', true);
});

it('résout le palier pro pour le staff (admin/editor) sans abonnement', function () {
    $editor = User::factory()->create();
    $editor->assignRole(Role::findOrCreate('editor'));

    $this->actingAs($editor)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.plan', 'pro')
        ->assertJsonPath('data.features.export', true);
});

it('un abonnement annulé et terminé ne compte plus comme pro', function () {
    $user = User::factory()->create();
    Subscription::factory()->for($user, 'user')->canceled()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.plan', 'libre');
});

it('reflète le quota assistant réellement consommé, à la même clé que le limiteur', function () {
    $user = User::factory()->create();

    // Même construction de clé que ThrottleRequests pour le palier standard
    // (mensuel) : ai_assistant + 'month:' + id, hachée en md5.
    $key = md5('ai_assistant'.'month:'.$user->id);
    RateLimiter::hit($key, 3600);
    RateLimiter::hit($key, 3600);

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.quotas.assistant.used', 2)
        ->assertJsonPath('data.quotas.assistant.resets_at', fn ($value) => $value !== null);
});

it('le quota assistant du palier user_pro est journalier, pas mensuel', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('user_pro'));

    $this->actingAs($user)
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.quotas.assistant.limit', config('ai.quotas.user_pro.per_day'));
});
