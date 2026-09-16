<?php

use App\Models\LegalDocument;
use App\Models\LegalWatchSubscription;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('liste les abonnements de l\'utilisateur courant, vide par défaut', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/watches')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('permet de s\'abonner à un texte', function () {
    $user = User::factory()->create();
    $document = LegalDocument::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/watches', [
        'watchable_type' => 'document',
        'watchable_id' => $document->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.watchable_type', LegalDocument::class)
        ->assertJsonPath('data.watchable_id', $document->id);

    expect(LegalWatchSubscription::where('user_id', $user->id)->count())->toBe(1);
});

it('permet de s\'abonner à un thème', function () {
    $user = User::factory()->create();
    $theme = Tag::create(['name' => 'Travail & emploi', 'slug' => 'travail']);

    $this->actingAs($user)->postJson('/api/v1/watches', [
        'watchable_type' => 'theme',
        'watchable_id' => $theme->id,
    ])->assertCreated();

    expect(LegalWatchSubscription::query()
        ->where('user_id', $user->id)
        ->where('watchable_type', Tag::class)
        ->where('watchable_id', $theme->id)
        ->exists())->toBeTrue();
});

it('est idempotent : s\'abonner deux fois à la même cible ne crée pas de doublon', function () {
    $user = User::factory()->create();
    $document = LegalDocument::factory()->create();

    $payload = ['watchable_type' => 'document', 'watchable_id' => $document->id];

    $this->actingAs($user)->postJson('/api/v1/watches', $payload)->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/watches', $payload)->assertCreated();

    expect(LegalWatchSubscription::where('user_id', $user->id)->count())->toBe(1);
});

it('rejette un type de cible inconnu', function () {
    $user = User::factory()->create();
    $document = LegalDocument::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/watches', [
        'watchable_type' => 'article',
        'watchable_id' => $document->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['watchable_type']);
});

it('rejette un identifiant de cible inexistant', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/watches', [
        'watchable_type' => 'document',
        'watchable_id' => (string) Str::uuid(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['watchable_id']);
});

it('rejette un texte supprimé (soft delete) comme cible', function () {
    $user = User::factory()->create();
    $document = LegalDocument::factory()->create();
    $document->delete();

    $this->actingAs($user)->postJson('/api/v1/watches', [
        'watchable_type' => 'document',
        'watchable_id' => $document->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['watchable_id']);
});

it('permet de se désabonner', function () {
    $user = User::factory()->create();
    $document = LegalDocument::factory()->create();
    $subscription = $user->legalWatchSubscriptions()->create([
        'watchable_type' => LegalDocument::class,
        'watchable_id' => $document->id,
    ]);

    $this->actingAs($user)->deleteJson("/api/v1/watches/{$subscription->id}")
        ->assertOk();

    $this->assertDatabaseMissing('legal_watch_subscriptions', ['id' => $subscription->id]);
});

it('ne permet pas de retirer l\'abonnement d\'un autre utilisateur', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $document = LegalDocument::factory()->create();
    $subscription = $owner->legalWatchSubscriptions()->create([
        'watchable_type' => LegalDocument::class,
        'watchable_id' => $document->id,
    ]);

    $this->actingAs($intruder)->deleteJson("/api/v1/watches/{$subscription->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('legal_watch_subscriptions', ['id' => $subscription->id]);
});
